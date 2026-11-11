<?php

namespace Filament\Resources\Pages\Concerns;

use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Renderless;

trait HasLiveListUpdates
{
    protected string | false | null $cachedListBroadcastChannel = false;

    protected function getListUpdateAudienceName(): ?string
    {
        return null;
    }

    #[Unexposed]
    public function hasLiveListUpdates(): bool
    {
        return $this->getListBroadcastChannel() !== null;
    }

    #[Unexposed]
    public function getListBroadcastChannel(): ?string
    {
        if ($this->cachedListBroadcastChannel !== false) {
            return $this->cachedListBroadcastChannel;
        }

        $this->cachedListBroadcastChannel = null;

        if (
            (! Filament::getCurrentOrDefaultPanel()->hasBroadcasting()) ||
            blank(config('filament.broadcasting.echo')) ||
            (($audience = $this->getListUpdateAudienceName()) === null) ||
            $this->getParentRecord()
        ) {
            return null;
        }

        $subscription = FilamentRealtime::getSubscription(static::getResource()::getModel());

        if ((! $subscription) || blank($subscription->getEvents()) || ($this->getTable()->getPaginationMode() === PaginationMode::Cursor)) {
            return null;
        }

        foreach ($this->getTable()->getColumns() as $column) {
            if ($column instanceof Editable) {
                return null;
            }
        }

        $record = $this->getTable()->getQuery()?->getModel();

        return $this->cachedListBroadcastChannel = $record ? $subscription->getListBroadcastChannel($record, $audience) : null;
    }

    /**
     * @return array<string, mixed>
     */
    #[Exposed]
    #[Renderless]
    public function authorizeListUpdates(string $socketId): array
    {
        static::authorizeResourceAccess();

        $channel = $this->getListBroadcastChannel();

        abort_if($channel === null, 403);

        Validator::make(['socketId' => $socketId], ['socketId' => ['required', 'regex:/\A\d+\.\d+\z/']])->validate();

        $request = clone request();
        $request->replace([
            'channel_name' => 'private-' . $channel,
            'socket_id' => $socketId,
        ]);

        return Broadcast::connection()->validAuthenticationResponse($request, true);
    }

    #[Exposed]
    public function refreshList(): void
    {
        static::authorizeResourceAccess();

        abort_unless($this->hasLiveListUpdates(), 403);

        if (
            $this->mountedActions ||
            $this->selectedTableRecords ||
            $this->isTrackingDeselectedTableRecords ||
            $this->isTableReordering ||
            ($this->getTable()->hasDeferredFilters() && ($this->tableFilters !== $this->tableDeferredFilters))
        ) {
            $this->skipRender();

            return;
        }

        $this->flushCachedTableRecords();
        $this->forceRender();
    }
}
