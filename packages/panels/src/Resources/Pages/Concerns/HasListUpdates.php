<?php

namespace Filament\Resources\Pages\Concerns;

use Filament\Facades\Filament;
use Filament\Resources\Events\ResourceListChanged;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use WeakMap;

trait HasListUpdates
{
    /**
     * `false` marks a lazy badge that hasn't loaded yet. A loaded badge can be `null`.
     *
     * @var array<array-key, string | null | false>
     */
    #[Locked]
    public array $renderedListTabBadges = [];

    #[Unexposed]
    public function getListUpdateChannel(): ?string
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $resource = static::getResource();

        if (
            blank(config('filament.broadcasting.echo')) ||
            (! $panel->hasBroadcasting()) ||
            blank(FilamentRealtime::getSubscription($resource::getModel())?->getEvents())
        ) {
            return null;
        }

        $table = $this->getTable();
        $tenant = Filament::getTenant();

        if (
            ($panel->getModelResource($resource::getModel()) !== $resource) ||
            $resource::getParentResourceRegistration() ||
            ($panel->hasTenancy() && $resource::isScopedToTenant() && (! $tenant)) ||
            (! $table->isLoaded()) ||
            (! $table->hasQuery()) ||
            ($table->getPaginationMode() === PaginationMode::Cursor)
        ) {
            return null;
        }

        foreach ($table->getColumns() as $column) {
            if ($column instanceof Editable) {
                return null;
            }
        }

        $model = $table->getQuery()?->getModel();

        if ((! $model) || ($model::class !== $resource::getModel())) {
            return null;
        }

        return ResourceListChanged::getChannel($panel, $resource, $model, $tenant);
    }

    /** @return array<string, mixed> */
    #[Exposed]
    #[Renderless]
    public function authorizeListUpdates(string $channel, string $socketId): array
    {
        $this->authorizeAccess();
        abort_unless($channel === $this->getListUpdateChannel(), 403);

        Validator::make(['socketId' => $socketId], ['socketId' => ['required', 'regex:/\A\d+\.\d+\z/']])->validate();

        $request = clone request();
        $request->replace(['channel_name' => 'private-' . $channel, 'socket_id' => $socketId]);

        return Broadcast::connection()->validAuthenticationResponse($request, true);
    }

    #[Exposed]
    #[Renderless]
    public function refreshList(string $channel, ?string $fingerprint): bool
    {
        $this->authorizeAccess();
        abort_unless($channel === $this->getListUpdateChannel(), 403);

        if ($this->mountedActions || $this->isTableReordering()) {
            return true;
        }

        $this->flushCachedTableRecords();

        if (
            blank($fingerprint) ||
            ($fingerprint !== $this->getListPageFingerprint()) ||
            ($this->renderedListTabBadges !== $this->getListTabBadges())
        ) {
            $this->forceRender();
        }

        return true;
    }

    #[Unexposed]
    public function renderingHasListUpdates(): void
    {
        $this->renderedListTabBadges = ($this->getCachedTabs() && $this->getListUpdateChannel()) ? $this->getListTabBadges() : [];
    }

    /** @param array<string, mixed> $arguments */
    #[Exposed]
    public function callSchemaComponentMethod(string $componentKey, string $method, array $arguments = []): mixed
    {
        $result = parent::callSchemaComponentMethod($componentKey, $method, $arguments);

        // A lazy badge's first displayed count arrives after the page HTML.
        if (
            $this->renderedListTabBadges &&
            ($componentKey === 'content.resourceTabs') &&
            in_array($method, ['getLazyLoadedTabBadges', 'getDeferredTabBadges'], strict: true) &&
            is_array($result)
        ) {
            foreach ($result as $key => $badge) {
                $this->renderedListTabBadges[$key] = $badge['badge'];
            }
        }

        return $result;
    }

    /** @return array<array-key, string | null | false> */
    protected function getListTabBadges(): array
    {
        $tabs = $this->getCachedTabs() ? $this->getSchemaComponent('content.resourceTabs') : null;

        if (! $tabs instanceof Tabs) {
            return [];
        }

        $badges = [];

        foreach ($tabs->getChildSchema()->getComponents(withOriginalKeys: true) as $key => $tab) {
            if (! $tab instanceof Tab) {
                continue;
            }

            if ($tab->isBadgeLazyLoaded()) {
                if ((! array_key_exists($key, $this->renderedListTabBadges)) || ($this->renderedListTabBadges[$key] === false)) {
                    $badges[$key] = false;

                    continue;
                }

                // Replace the Alpine placeholder when this badge first renders as HTML.
                $tab->extraAttributes(['wire:key' => $tab->getLivewireKey() . '.loaded-badge'], merge: true);
                $tab->lazyLoadedBadge(false);
            }

            $badges[$key] = $badge = $tab->getBadge();

            // Render the same value that was compared, without repeating its count query.
            if ($tab->hasBadge()) {
                $tab->badge(static fn (): ?string => $badge);
            }
        }

        return $badges;
    }

    #[Unexposed]
    public function getListPageFingerprint(): ?string
    {
        $table = $this->getTable();

        if ($table->getGrouping() || $table->hasColumnsLayout() || $table->hasSummary(null) || $table->getContent() || $table->getContentFooter()) {
            return null;
        }

        $records = $this->getTableRecords();
        $page = [];
        $seen = new WeakMap;

        if ($records instanceof Paginator) {
            $page[] = [$records->currentPage(), $records->perPage(), $records->hasMorePages(), ($records instanceof LengthAwarePaginator) ? $records->total() : null];
        }

        foreach ($records as $record) {
            $page[] = $this->getListRecordFingerprintData($record, $seen);
        }

        // Only the keyed digest leaves the server, including for hidden attributes.
        return hash_hmac('sha256', serialize($page), app('encrypter')->getKey());
    }

    /**
     * @param  WeakMap<object, int>  $seen
     * @return array<mixed>
     */
    protected function getListRecordFingerprintData(Model $record, WeakMap $seen): array
    {
        // Inverse relationships can point back to a model already on this page.
        if (isset($seen[$record])) {
            return ['reference' => $seen[$record]];
        }

        $seen[$record] = count($seen);
        $relations = [];

        foreach ($record->getRelations() as $name => $relation) {
            $relations[$name] = match (true) {
                $relation instanceof Model => $this->getListRecordFingerprintData($relation, $seen),
                $relation instanceof Collection => $relation->map(fn (Model $relatedRecord): array => $this->getListRecordFingerprintData($relatedRecord, $seen))->all(),
                default => $relation,
            };
        }

        return [$record::class, $record->getRawOriginal(), $relations];
    }
}
