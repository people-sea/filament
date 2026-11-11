<?php

namespace Filament\Support\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ModelListChangedBroadcast implements ShouldBroadcast
{
    public function __construct(protected string $channel) {}

    /**
     * @param  array<mixed>  $coordinates  Unvalidated coordinates returned by application callbacks.
     */
    public static function getChannel(Model $record, string $audience, array $coordinates): string
    {
        if (blank($audience) || (! count($coordinates))) {
            throw new InvalidArgumentException('A list audience requires a name and nonempty coordinates.');
        }

        foreach ($coordinates as $name => $value) {
            if ((! is_string($name)) || blank($name) || ((! is_int($value)) && (! is_string($value))) || ((string) $value === '')) {
                throw new InvalidArgumentException('List audience coordinates must have named, nonempty integer or string identifiers.');
            }

            $coordinates[$name] = (string) $value;
        }

        ksort($coordinates, SORT_STRING);

        return 'filament.lists.' . hash('sha256', serialize([
            $record::class,
            $record->getConnection()->getName(),
            $record->getConnection()->getDatabaseName(),
            $audience,
            $coordinates,
        ]));
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'filament.model-list-changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
