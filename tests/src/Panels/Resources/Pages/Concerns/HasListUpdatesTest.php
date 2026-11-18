<?php

use Filament\Facades\Filament;
use Filament\Resources\Events\ResourceListChanged;
use Filament\Support\Enums\ModelEvent;
use Filament\Support\Events\RecordChanged;
use Filament\Support\Events\RecordChangedBroadcast;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Filament\Tests\Fixtures\Models\Post;
use Filament\Tests\Fixtures\Models\Team;
use Filament\Tests\Fixtures\Models\Ticket;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Policies\TicketPolicy;
use Filament\Tests\Fixtures\Resources\Posts\Pages\ListPosts;
use Filament\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Tests\Fixtures\Resources\Tickets\Pages\ListTickets;
use Filament\Tests\Panels\Resources\TestCase;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\HtmlString;

use function Filament\Tests\livewire;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'filament.broadcasting.echo' => ['broadcaster' => 'null'],
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => ['driver' => 'pusher', 'key' => 'list-key', 'secret' => 'list-secret', 'app_id' => 'list', 'options' => ['cluster' => 'mt1']],
    ]);
    Event::fake([RecordChangedBroadcast::class, ResourceListChanged::class]);
});

it('rechecks the filtered and sorted page and renders only changed rows or pagination', function (): void {
    FilamentRealtime::subscribe(Post::class)->onlyEvents([ModelEvent::Updated]);
    foreach (range(1, 12) as $index) {
        $posts[] = Post::factory()->create(['title' => sprintf('Report %02d', $index), 'is_published' => true]);
    }
    $page = livewire(ListPosts::class)->filterTable('is_published')->searchTable('Report')->sortTable('title')->call('setPage', 2);
    $channel = $page->instance()->getListUpdateChannel();
    $fingerprint = $page->instance()->getListPageFingerprint();
    expect($fingerprint)->not->toBeNull();

    $posts[0]->update(['content' => 'Off the page']);
    $page->call('refreshList', $channel, $fingerprint)->assertReturned(true);
    expect($page->effects)->not->toHaveKey('html');

    $posts[10]->author->update(['name' => 'Renamed author']);
    $page->call('refreshList', $channel, $fingerprint)->assertSee('Renamed author');
    expect($page->effects)->toHaveKey('html');
    $fingerprint = $page->instance()->getListPageFingerprint();

    $posts[0]->delete();
    Post::factory()->create(['title' => 'Report 00', 'is_published' => true]);
    $page->call('refreshList', $channel, $fingerprint);
    expect($page->effects)->not->toHaveKey('html');

    // Move a record out of the filter. The page number stays put as rows move.
    $posts[1]->update(['is_published' => false]);
    $page->call('refreshList', $channel, $fingerprint)->assertCanSeeTableRecords([$posts[11]])->assertCanNotSeeTableRecords([$posts[10]])
        ->assertSet('paginators.page', 2)->assertSet('tableSearch', 'Report');
    $page->call('setPage', 1);
    $fingerprint = $page->instance()->getListPageFingerprint();
    Post::factory()->create(['title' => 'Report 99', 'is_published' => true]);
    $page->call('refreshList', $channel, $fingerprint);
    expect($page->effects)->toHaveKey('html');
});

it('renders custom column state even when the visible models have not changed', function (): void {
    FilamentRealtime::subscribe(Post::class);
    Post::factory()->create();
    TextColumn::configureUsing(static fn (TextColumn $column) => $column->state(static fn (): string => config('list-test-value')));
    config(['list-test-value' => 'First value']);
    $page = livewire(ListPosts::class)->assertSee('First value');
    config(['list-test-value' => 'Changed elsewhere']);
    $page->call('refreshList', $page->instance()->getListUpdateChannel(), $page->instance()->getListPageFingerprint())
        ->assertSee('Changed elsewhere');
});

it('shares only an empty signal within a tenant and keeps each reader’s row scope', function (): void {
    $tenant = User::factory()->create();
    $otherTenant = User::factory()->create();
    $reader = User::factory()->create();
    $otherReader = User::factory()->create();
    $draft = Post::factory()->create(['author_id' => $tenant->getKey(), 'is_published' => false]);
    $panel = Filament::getPanel('tenancy')->tenant(User::class, ownershipRelationship: 'author');
    Filament::setCurrentPanel($panel);
    Filament::setTenant($tenant);
    PostResource::registerTenancyModelGlobalScope($panel);
    FilamentRealtime::subscribe(Post::class)->onlyEvents([ModelEvent::Updated]);
    Table::configureUsing(static fn (Table $table) => $table->modifyQueryUsing(static fn (Builder $query) => $query->when(auth()->id() === $otherReader->getKey(), static fn (Builder $query) => $query->where('is_published', true))));

    $this->actingAs($reader);
    $page = livewire(ListPosts::class)->assertCanSeeTableRecords([$draft]);
    $fingerprint = $page->instance()->getListPageFingerprint();
    $channel = $page->instance()->getListUpdateChannel();
    $signature = ['auth' => 'list-key:' . hash_hmac('sha256', '12.34:private-' . $channel, 'list-secret')];
    $page->call('authorizeListUpdates', $channel, '12.34')->assertReturned($signature);
    $this->actingAs($otherReader);
    $otherPage = livewire(ListPosts::class)->assertCanNotSeeTableRecords([$draft])->call('authorizeListUpdates', $channel, '12.34')->assertReturned($signature);
    $otherFingerprint = $otherPage->instance()->getListPageFingerprint();

    Filament::setTenant($otherTenant);
    $otherChannel = livewire(ListPosts::class)->instance()->getListUpdateChannel();
    expect($otherChannel)->not->toBeNull()->not->toBe($channel);
    livewire(ListPosts::class)->call('authorizeListUpdates', $channel, '12.34')->assertForbidden();
    Filament::setTenant(null);
    Filament::setCurrentPanel(null);

    DB::transaction(function () use ($draft): void {
        $draft->update(['title' => 'Private draft revision']);
        Event::assertNotDispatched(ResourceListChanged::class);
    });
    Event::assertDispatchedTimes(ResourceListChanged::class, 1);
    Event::assertDispatched(ResourceListChanged::class, static function (ResourceListChanged $event) use ($channel, $otherChannel): bool {
        $channels = array_map(strval(...), $event->broadcastOn());

        return ($event->broadcastWith() === []) && in_array('private-' . $channel, $channels, true) && (! in_array('private-' . $otherChannel, $channels, true));
    });
    RecordChanged::make($draft)->event('retrieved')->dispatch();
    Event::assertDispatchedTimes(ResourceListChanged::class, 1);

    Filament::setCurrentPanel($panel);
    Filament::setTenant($tenant);
    $otherPage->call('refreshList', $channel, $otherFingerprint);
    expect($otherPage->effects)->not->toHaveKey('html');
    $this->actingAs($reader);
    $page->call('refreshList', $channel, $fingerprint)->assertSee('Private draft revision');
});

it('checks the effective subscription and current resource access on every refresh and signing request', function (string $method, ?string $argument): void {
    $page = livewire(ListTickets::class);
    expect($page->instance()->getListUpdateChannel())->toBeNull();
    FilamentRealtime::subscribe(Ticket::class);
    $channel = $page->instance()->getListUpdateChannel();
    expect($channel)->not->toBeNull();
    app()->bind(TicketPolicy::class . '::viewAny', static fn (): bool => false);
    $page->call($method, $channel, $argument)->assertForbidden();
})->with([['authorizeListUpdates', '12.34'], ['refreshList', null]]);

it('does not fall back to a model-wide channel when tenant ownership cannot be inferred', function (): void {
    FilamentRealtime::subscribe(Post::class);
    $globalChannel = livewire(ListPosts::class)->instance()->getListUpdateChannel();
    expect($globalChannel)->not->toBeNull();
    Filament::setCurrentPanel(Filament::getPanel('tenancy'));
    Filament::setTenant(Team::factory()->create()); // Posts belong to a team through their author.

    $page = livewire(ListPosts::class);
    expect($page->instance()->getListUpdateChannel())->toBeNull();
    $page->call('authorizeListUpdates', $globalChannel, '12.34')->assertForbidden();
});

it('leaves inline-editable lists alone', function (): void {
    FilamentRealtime::subscribe(Post::class);
    expect(livewire(ListPostsWithEditableColumn::class)->instance()->getListUpdateChannel())->toBeNull();
});

it('catches up after deselection and resubscription without remounting the list', function (): void {
    Artisan::call('filament:assets');
    FilamentRealtime::subscribe(Post::class)->onlyEvents([ModelEvent::Updated]);
    $post = Post::factory()->create(['title' => 'Original title']);

    // Drive Echo delivery explicitly. Signing, Livewire transport and the table are real.
    FilamentView::registerRenderHook(PanelsRenderHook::SCRIPTS_AFTER, static fn (): HtmlString => new HtmlString(<<<'HTML'
        <script>
            window.EchoFactory = class {
                constructor(configuration) { this.configuration = configuration }
                private(channelName) {
                    const delivery = window.listTestDelivery = {}
                    const channel = {
                        listen(event, callback) {
                            if (event === '.filament.list-changed') delivery.notify = callback
                            return channel
                        },
                        subscribed: callback => {
                            delivery.subscribe = () => this.configuration.channelAuthorization.customHandler({socketId: '123.456', channelName}, (error, authorization) => {
                                if (error || !authorization?.auth) return
                                delivery.subscribed = true
                                callback()
                            })
                            delivery.subscribe()
                            return channel
                        },
                    }
                    return channel
                }
                disconnect() {}
                leave() {}
            }
        </script>
        HTML));

    $page = visit(PostResource::getUrl())->assertScript('window.listTestDelivery?.subscribed', true);
    $checkbox = 'tbody input[type="checkbox"][value="' . $post->getKey() . '"]';
    $page->check($checkbox)->assertChecked($checkbox);
    $post->update(['title' => 'Revised while selected']);
    $page->script('window.listTestDelivery.notify({}); undefined');
    $page->assertSee('Original title')->assertChecked($checkbox)
        ->uncheck($checkbox)->assertSee('Revised while selected');

    $page->check($checkbox)->assertChecked($checkbox);
    $post->update(['title' => 'Changed while disconnected']);
    $page->script('window.listTestDelivery.subscribe(); undefined');
    $page->assertNoAccessibilityIssues()->assertSee('Revised while selected')->assertChecked($checkbox)
        ->uncheck($checkbox)->assertSee('Changed while disconnected');

    $darkPage = visit(PostResource::getUrl())->inDarkMode()->assertScript('window.listTestDelivery?.subscribed', true);
    $darkPage->check($checkbox)->assertChecked($checkbox)->assertNoAccessibilityIssues();
});

class ListPostsWithEditableColumn extends ListPosts
{
    public function table(Table $table): Table
    {
        return $table->columns([TextInputColumn::make('title')]);
    }
}
