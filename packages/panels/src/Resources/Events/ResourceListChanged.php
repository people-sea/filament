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
        $eventName = ($eventName instanceof BackedEnum) ? (string) $eventName->value : $eventName;

        if ((! config('filament.broadcasting.echo')) || ($eventName === 'retrieved')) {
            return;
        }

        $record = $event->getRecord();
        $channels = [];

        foreach (Filament::getPanels() as $panel) {
            $resource = $panel->getModelResource($record);

            if ((! $resource) || $resource::getParentResourceRegistration()) {
                continue;
            }

            foreach (static::getChannels($panel, $resource, $record, eventName: $eventName) as $channel) {
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
        return static::getChannels($panel, $resource, $model, $tenant)[0] ?? null;
    }

    /**
     * @param  class-string<PanelResource>  $resource
     * @return array<string>
     */
    protected static function getChannels(Panel $panel, string $resource, Model $model, ?Model $tenant = null, ?string $eventName = null): array
    {
        $scope = [$model::class, $model->getConnection()->getName(), $model->getConnection()->getDatabaseName()];

        if ((! $panel->hasTenancy()) || (! $resource::isScopedToTenant())) {
            return ['filament.lists.' . hash('sha256', serialize($scope))];
        }

        $relationship = $resource::getListBroadcastTenantRelationship($panel, $model);

        if (! $relationship) {
            return [];
        }

        $owner = $tenant ?? $relationship->getRelated();

        if ($owner::class !== $panel->getTenantModel()) {
            return [];
        }

        $foreignKey = $relationship->getForeignKeyName();

        if ($tenant) {
            $keys = [$tenant->getKey()];
        } else {
            $attributes = in_array($eventName, ['deleted', 'trashed', 'forceDeleted'], strict: true)
                ? $model->getRawOriginal()
                : $model->getAttributes();

            if ($model->exists && (! array_key_exists($foreignKey, $attributes))) {
                $attributes[$foreignKey] = $model->newModelQuery()->whereKey($model->getKey())->toBase()->useWritePdo()->value($foreignKey);
            }

            $keys = [$attributes[$foreignKey] ?? null];

            if (in_array($eventName, ['updated', 'saved'], strict: true) && $model->wasChanged($foreignKey)) {
                $keys[] = $model->getRawOriginal($foreignKey);
            }
        }

        $channels = [];

        foreach (array_unique($keys) as $key) {
            if (filled($key)) {
                $tenantScope = [$owner::class, $owner->getConnection()->getName(), $owner->getConnection()->getDatabaseName(), $foreignKey, (string) $key];
                $channels[] = 'filament.lists.' . hash('sha256', serialize([...$scope, $tenantScope]));
            }
        }

        return $channels;
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
