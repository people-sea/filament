<?php

use Filament\Facades\Filament;
use Filament\Support\Enums\ModelEvent;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Tests\Fixtures\Models\Post;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Tests\Panels\Resources\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;

uses(TestCase::class);

// Run against a Pusher-compatible server using `FILAMENT_TEST_BROADCAST_HOST`,
// `FILAMENT_TEST_BROADCAST_PORT` and the standard `PUSHER_APP_*` credentials.
it('defers real list invalidations during selection, filter input and modal drafts', function (): void {
    $host = getenv('FILAMENT_TEST_BROADCAST_HOST');
    $port = (int) (getenv('FILAMENT_TEST_BROADCAST_PORT') ?: 6001);
    config([
        'queue.default' => 'sync',
        'broadcasting.default' => 'list-browser',
        'broadcasting.connections.list-browser' => [
            'driver' => 'pusher',
            'key' => getenv('PUSHER_APP_KEY'),
            'secret' => getenv('PUSHER_APP_SECRET'),
            'app_id' => getenv('PUSHER_APP_ID'),
            'options' => ['host' => $host, 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
        ],
        'filament.broadcasting.echo' => [
            'broadcaster' => 'pusher',
            'key' => getenv('PUSHER_APP_KEY'),
            'wsHost' => $host,
            'wsPort' => $port,
            'forceTLS' => false,
            'enabledTransports' => ['ws'],
            'disableStats' => true,
        ],
    ]);
    Broadcast::routes();
    Broadcast::channel(str_replace('\\', '.', User::class) . '.{userId}', static fn (User $user, string $userId): bool => (string) $user->getKey() === $userId);
    Artisan::call('filament:assets');
    FilamentRealtime::subscribe(Post::class)->onlyEvents([ModelEvent::Created, ModelEvent::Updated])->listAudience(
        'owners',
        publishUsing: static fn (Post $record): array => [['owner' => $record->author_id]],
        subscribeUsing: static fn (): array => ['owner' => Filament::auth()->id()],
    );

    $page = visit(PostResource::getUrl('live', isAbsolute: false));
    $page->page()->context()->addInitScript(<<<'JS'
        window.listNotifications = 0
        window.WebSocket = class extends WebSocket {
            constructor(...arguments_) {
                super(...arguments_)
                this.addEventListener('message', ({ data }) => {
                    if (JSON.parse(data).event === 'filament.model-list-changed') window.listNotifications++
                })
            }
        }
        JS);
    $page->refresh()->assertNoJavaScriptErrors();

    $post = Post::factory()->create(['title' => 'Live arrival', 'author_id' => auth()->id()]);
    $page->assertSee('Live arrival')->assertNoAccessibilityIssues();
    $notifications = $page->script('window.listNotifications');
    $selection = 'input[aria-label="Select/deselect item ' . $post->getKey() . ' for bulk actions."]';

    $page->check($selection);
    $post->update(['title' => 'Selection catch-up']);
    $page->assertScript('window.listNotifications > ' . $notifications)
        ->assertChecked($selection)
        ->assertSee('Live arrival')
        ->assertDontSee('Selection catch-up')
        ->uncheck($selection)
        ->assertSee('Selection catch-up');

    $notifications = $page->script('window.listNotifications');
    $page->click('[data-testid="live-list-filters"]');
    $post->update(['title' => 'Filter catch-up']);
    $page->assertScript('window.listNotifications > ' . $notifications)
        ->assertSee('Selection catch-up')
        ->assertDontSee('Filter catch-up')
        ->keys('[data-testid="live-list-apply-filters"]', 'Escape')
        ->assertSee('Filter catch-up');

    $page->click('Note')->fill('[data-testid="live-list-note"]', 'Keep this unsaved note');
    $notifications = $page->script('window.listNotifications');
    $post->update(['title' => 'Modal catch-up']);
    $page->assertScript('window.listNotifications > ' . $notifications)
        ->assertValue('[data-testid="live-list-note"]', 'Keep this unsaved note')
        ->assertDontSee('Modal catch-up')
        ->assertNoAccessibilityIssues();
    $page->press('Cancel')->assertSee('Modal catch-up')->assertNoJavaScriptErrors();

    $darkPage = visit(PostResource::getUrl('live', isAbsolute: false))->inDarkMode()
        ->assertSee('Modal catch-up')->assertNoAccessibilityIssues();
    $darkPage->click('Note')->assertNoAccessibilityIssues();
})->skip(fn (): bool => ! getenv('FILAMENT_TEST_BROADCAST_HOST'), 'Requires a configured Pusher-compatible websocket server.');
