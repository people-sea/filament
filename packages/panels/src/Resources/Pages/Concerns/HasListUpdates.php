<?php

namespace Filament\Resources\Pages\Concerns;

use Filament\Facades\Filament;
use Filament\Resources\Events\ResourceListChanged;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Renderless;

trait HasListUpdates
{
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

        if (blank($fingerprint) || ($fingerprint !== $this->getListPageFingerprint())) {
            $this->forceRender();
        }

        return true;
    }

    #[Unexposed]
    public function getListPageFingerprint(): ?string
    {
        $table = $this->getTable();

        if ($table->getGrouping() || $table->hasColumnsLayout() || $table->hasSummary(null) || $table->getContent() || $table->getContentFooter()) {
            return null;
        }

        $columns = $table->getVisibleColumns();

        foreach ($columns as $name => $column) {
            if (($column::class !== TextColumn::class) || ($column->getStateUsingCallback() !== null) || $column->hasStateFormatting() || $column->isStateLazyLoaded()) {
                return null;
            }

            $columns[$name] = clone $column;
        }

        $records = $this->getTableRecords();
        $page = [array_keys($columns)];

        if ($records instanceof Paginator) {
            $page[] = [$records->currentPage(), $records->perPage(), $records->hasMorePages(), ($records instanceof LengthAwarePaginator) ? $records->total() : null];
        }

        foreach ($records as $record) {
            $row = [$record->getKey(), $record->getRawOriginal()];

            foreach ($columns as $column) {
                $value = $column->record($record)->getState();

                if (($value !== null) && (! is_scalar($value))) {
                    return null;
                }

                $row[] = $value;
            }

            $page[] = $row;
        }

        // Only the keyed digest leaves the server, including for hidden attributes.
        return hash_hmac('sha256', serialize($page), app('encrypter')->getKey());
    }
}
