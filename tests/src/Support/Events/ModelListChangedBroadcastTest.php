<?php

use Filament\Support\Enums\ModelEvent;
use Filament\Support\Events\ModelChangedBroadcast;
use Filament\Support\Events\ModelListChangedBroadcast;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Tests\Fixtures\Models\Post;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(TestCase::class);

it('invalidates only the configured owner audience after commit while retaining record notifications', function (): void {
    $post = Post::factory()->create(['is_published' => false]);
    $subscription = FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([ModelEvent::Updated])
        ->listAudience(
            'owners',
            publishUsing: static fn (Post $record): array => [['tenant' => 7, 'owner' => $record->author_id]],
            subscribeUsing: static fn (): array => ['owner' => $post->author_id, 'tenant' => 7],
        );
    $ownerChannel = $subscription->getListBroadcastChannel($post);
    $otherChannel = ModelListChangedBroadcast::getChannel($post, 'owners', ['tenant' => 7, 'owner' => $post->author_id + 1]);
    Event::fake([ModelChangedBroadcast::class, ModelListChangedBroadcast::class]);

    $post->update(['title' => 'Without Echo']);
    Event::assertNotDispatched(ModelListChangedBroadcast::class);

    config(['filament.broadcasting.echo' => ['broadcaster' => 'pusher']]);
    DB::beginTransaction();
    $post->update(['title' => 'Rolled back']);
    Event::assertNotDispatched(ModelListChangedBroadcast::class);
    DB::rollBack();

    DB::transaction(function () use ($post): void {
        $post->update(['is_published' => true]);
        Event::assertNotDispatched(ModelListChangedBroadcast::class);
    });

    Event::assertDispatchedTimes(ModelListChangedBroadcast::class, 1);
    Event::assertDispatchedTimes(ModelChangedBroadcast::class, 1);
    Event::assertDispatched(ModelListChangedBroadcast::class, static fn (ModelListChangedBroadcast $event): bool => ((string) $event->broadcastOn() === 'private-' . $ownerChannel)
        && ((string) $event->broadcastOn() !== 'private-' . $otherChannel)
        && ($event->broadcastWith() === []));

    $subscription->listAudience(null);
    $post->update(['title' => 'Record-only again']);

    Event::assertDispatchedTimes(ModelListChangedBroadcast::class, 1);
    Event::assertDispatchedTimes(ModelChangedBroadcast::class, 2);
});

it('separates list identities by tenant, audience, model and database', function (): void {
    $record = new Post;
    $channel = ModelListChangedBroadcast::getChannel($record, 'owners', ['tenant' => 7, 'owner' => 11]);

    expect(ModelListChangedBroadcast::getChannel($record, 'owners', ['tenant' => 8, 'owner' => 11]))->not->toBe($channel)
        ->and(ModelListChangedBroadcast::getChannel($record, 'reviewers', ['tenant' => 7, 'owner' => 11]))->not->toBe($channel)
        ->and(ModelListChangedBroadcast::getChannel(new User, 'owners', ['tenant' => 7, 'owner' => 11]))->not->toBe($channel);

    $record->setConnection('different-list-database');
    config(['database.connections.different-list-database' => ['driver' => 'sqlite', 'database' => ':memory:']]);

    expect(ModelListChangedBroadcast::getChannel($record, 'owners', ['tenant' => 7, 'owner' => 11]))->not->toBe($channel);
});

it('rejects invalid audience coordinates instead of widening the audience', function (array $coordinates): void {
    ModelListChangedBroadcast::getChannel(new Post, 'owners', $coordinates);
})->with([
    'empty' => [[]],
    'unnamed' => [[7, 11]],
    'missing owner' => [['tenant' => 7, 'owner' => null]],
    'empty owner' => [['tenant' => 7, 'owner' => '']],
    'boolean owner' => [['tenant' => 7, 'owner' => true]],
])->throws(InvalidArgumentException::class);
