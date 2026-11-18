<?php

namespace Filament\Resources\Events;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource as PanelResource;
use Filament\Support\Events\RecordChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;

/** @internal */
class ResourceListChanged implements ShouldBroadcast
{
    /** @param array<string> $channels */
    public function __construct(protected array $channels) {}

    public static function dispatchForRecord(RecordChanged $event): void
    {
        $eventName = $event->getEvent();

        if ((! config('filament.broadcasting.echo')) || ((($eventName instanceof BackedEnum) ? $eventName->value : $eventName) === 'retrieved')) {
            return;
        }

        $record = $event->getRecord();
        $channels = [];

        foreach (Filament::getPanels() as $panel) {
            $resource = $panel->getModelResource($record);

            if ((! $resource) || $resource::getParentResourceRegistration()) {
                continue;
            }

            if ($channel = static::getChannel($panel, $resource, $record)) {
                $channels[$channel] = $channel;
            }
        }

        if ($channels !== []) {
            event(app(static::class, ['channels' => array_values($channels)]));
        }
    }

    /** @param class-string<PanelResource> $resource */
    public static function getChannel(Panel $panel, string $resource, Model $model, ?Model $tenant = null): ?string
    {
        $scope = [$model::class, $model->getConnection()->getName(), $model->getConnection()->getDatabaseName()];

        if ($panel->hasTenancy() && $resource::isScopedToTenant()) {
            $relationship = $resource::getListBroadcastTenantRelationship($panel, $model);

            if (! $relationship) {
                return null;
            }

            $owner = $tenant ?? $relationship->getRelated();
            $key = $tenant ? $tenant->getKey() : ($model->getAttributes()[$relationship->getForeignKeyName()] ?? null);

            if (($owner::class !== $panel->getTenantModel()) || blank($key)) {
                return null;
            }

            $scope[] = [$owner::class, $owner->getConnection()->getName(), $owner->getConnection()->getDatabaseName(), $relationship->getForeignKeyName(), (string) $key];
        }

        return 'filament.lists.' . hash('sha256', serialize($scope));
    }

    /** @return array<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $channel): PrivateChannel => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'filament.list-changed';
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
