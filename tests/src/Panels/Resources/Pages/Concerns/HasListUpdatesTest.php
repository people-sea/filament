<?php

use Filament\Facades\Filament;
use Filament\Resources\Events\ResourceListChanged;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\ModelEvent;
use Filament\Support\Events\RecordChanged;
use Filament\Support\Events\RecordChangedBroadcast;
use Filament\Support\Facades\FilamentRealtime;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Filament\Tests\Fixtures\Models\Post;
use Filament\Tests\Fixtures\Models\Team;
use Filament\Tests\Fixtures\Models\Ticket;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Policies\TicketPolicy;
use Filament\Tests\Fixtures\Resources\Posts\Pages\ListPosts;
use Filament\Tests\Fixtures\Resources\Posts\Pages\ListPostsWithTabs;
use Filament\Tests\Fixtures\Resources\Posts\PostResource;
use Filament\Tests\Fixtures\Resources\Tickets\Pages\ListTickets;
use Filament\Tests\Panels\Resources\TestCase;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

use function Filament\Tests\livewire;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'filament.broadcasting.echo' => ['broadcaster' => 'null'],
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => [
            'driver' => 'pusher',
            'key' => 'list-key',
            'secret' => 'list-secret',
            'app_id' => 'list',
            'options' => ['cluster' => 'mt1'],
        ],
    ]);

    Event::fake([RecordChangedBroadcast::class, ResourceListChanged::class]);
});

it('rechecks the filtered and sorted page and renders only changed rows or pagination', function (): void {
    FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([ModelEvent::Updated]);

    foreach (range(1, 12) as $index) {
        $posts[] = Post::factory()->create([
            'title' => sprintf('Report %02d', $index),
            'is_published' => true,
        ]);
    }

    $page = livewire(ListPosts::class)
        ->filterTable('is_published')
        ->searchTable('Report')
        ->sortTable('title')
        ->call('setPage', 2);

    $channel = $page->instance()
        ->getListUpdateChannel();
    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    expect($fingerprint)->not->toBeNull();

    $posts[0]->update(['content' => 'Off the page']);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertReturned(true);

    expect($page->effects)->not->toHaveKey('html');

    $posts[10]->author->update(['name' => 'Renamed author']);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertSee('Renamed author');

    expect($page->effects)->toHaveKey('html');

    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    $posts[0]->delete();
    Post::factory()->create(['title' => 'Report 00', 'is_published' => true]);

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)->not->toHaveKey('html');

    // Move a record out of the filter. The page number stays put as rows move.
    $posts[1]->update(['is_published' => false]);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertCanSeeTableRecords([$posts[11]])
        ->assertCanNotSeeTableRecords([$posts[10]])
        ->assertSet('paginators.page', 2)
        ->assertSet('tableSearch', 'Report');

    $page->call('setPage', 1);
    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    Post::factory()->create(['title' => 'Report 99', 'is_published' => true]);

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)->toHaveKey('html');
});

it('compares loaded records and nested relations without evaluating column state', function (): void {
    FilamentRealtime::subscribe(Post::class);
    $post = Post::factory()->create(['is_published' => true]);

    Table::configureUsing(static fn (Table $table) => $table->modifyQueryUsing(
        static fn (Builder $query) => $query
            ->where('is_published', true)
            ->with([
                'author.posts' => static fn (HasMany $posts) => $posts
                    ->chaperone('author')
                    ->orderBy('id'),
            ]),
    ));

    TextColumn::configureUsing(static fn (TextColumn $column) => $column->state(
        static fn (Post $record): string => $record->author->posts
            ->pluck('title')
            ->join(', '),
    ));

    $page = livewire(ListPosts::class)
        ->assertSee($post->title);

    $channel = $page->instance()
        ->getListUpdateChannel();
    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)->not->toHaveKey('html');

    $draft = Post::factory()->create([
        'author_id' => $post->author_id,
        'is_published' => false,
        'title' => 'Related draft',
    ]);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertSee('Related draft')
        ->assertCanNotSeeTableRecords([$draft]);

    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    $draft->update(['title' => 'Related revision']);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertSee('Related revision');
});

it('always renders table summaries on list checks', function (): void {
    FilamentRealtime::subscribe(Post::class);
    $post = Post::factory()->create(['rating' => 41]);

    $page = livewire(ListPostsWithSummary::class)
        ->assertSee('$41.00');

    $channel = $page->instance()
        ->getListUpdateChannel();

    $page->call(
        'refreshList',
        $channel,
        $page->instance()
            ->getListPageFingerprint(),
    );

    expect($page->effects)->toHaveKey('html');

    $post->update(['rating' => 52]);

    $page->call(
        'refreshList',
        $channel,
        $page->instance()
            ->getListPageFingerprint(),
    )->assertSee('$52.00');
});

it('renders only when the active page or a displayed tab count changes', function (): void {
    FilamentRealtime::subscribe(Post::class);
    $published = Post::factory()->create(['is_published' => true]);
    $draft = Post::factory()->create(['is_published' => false, 'rating' => 0]);

    $page = livewire(ListPostsWithTabCounts::class)
        ->assertCanSeeTableRecords([$published]);

    $channel = $page->instance()
        ->getListUpdateChannel();
    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    $draft->update(['rating' => 5]);

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)
        ->not->toHaveKey('html')
        ->and($page->effects['partials'] ?? [])
        ->toBeEmpty();

    $page->call('callSchemaComponentMethod', 'content.resourceTabs', 'getLazyLoadedTabBadges')
        ->assertReturned(fn (array $badges): bool => $badges['lazy']['badge'] === '1');

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)
        ->not->toHaveKey('html')
        ->and($page->effects['partials'] ?? [])
        ->toBeEmpty();

    $draft->update(['rating' => 0]);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertSet('activeTab', 'published')
        ->assertCanNotSeeTableRecords([$draft]);

    expect($page->effects)->toHaveKey('html');

    $fingerprint = $page->instance()
        ->getListPageFingerprint();

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)
        ->not->toHaveKey('html')
        ->and($page->effects['partials'] ?? [])
        ->toBeEmpty();

    $draft->delete();

    $page->call('refreshList', $channel, $fingerprint);

    expect($page->effects)->toHaveKey('html');
});

it('shares only an empty signal within a tenant and keeps each reader’s row scope', function (): void {
    $tenant = User::factory()->create();
    $otherTenant = User::factory()->create();
    $reader = User::factory()->create();
    $otherReader = User::factory()->create();
    $draft = Post::factory()->create(['author_id' => $tenant->getKey(), 'is_published' => false]);

    $panel = Filament::getPanel('tenancy')
        ->tenant(User::class, ownershipRelationship: 'author');

    Filament::setCurrentPanel($panel);
    Filament::setTenant($tenant);
    PostResource::registerTenancyModelGlobalScope($panel);

    FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([ModelEvent::Updated]);

    Table::configureUsing(static fn (Table $table) => $table->modifyQueryUsing(
        static fn (Builder $query) => $query->when(
            auth()->id() === $otherReader->getKey(),
            static fn (Builder $query) => $query->where('is_published', true),
        ),
    ));

    $this->actingAs($reader);

    $page = livewire(ListPosts::class)
        ->assertCanSeeTableRecords([$draft]);

    $fingerprint = $page->instance()
        ->getListPageFingerprint();
    $channel = $page->instance()
        ->getListUpdateChannel();
    $signature = [
        'auth' => 'list-key:' . hash_hmac('sha256', '12.34:private-' . $channel, 'list-secret'),
    ];

    $page->call('authorizeListUpdates', $channel, '12.34')
        ->assertReturned($signature);

    $this->actingAs($otherReader);

    $otherPage = livewire(ListPosts::class)
        ->assertCanNotSeeTableRecords([$draft])
        ->call('authorizeListUpdates', $channel, '12.34')
        ->assertReturned($signature);

    $otherFingerprint = $otherPage->instance()
        ->getListPageFingerprint();

    Filament::setTenant($otherTenant);

    $otherChannel = livewire(ListPosts::class)
        ->instance()
        ->getListUpdateChannel();

    expect($otherChannel)
        ->not->toBeNull()
        ->not->toBe($channel);

    livewire(ListPosts::class)
        ->call('authorizeListUpdates', $channel, '12.34')
        ->assertForbidden();

    Filament::setTenant(null);
    Filament::setCurrentPanel(null);

    DB::transaction(function () use ($draft): void {
        $draft->update(['title' => 'Private draft revision']);

        Event::assertNotDispatched(ResourceListChanged::class);
    });

    Event::assertDispatchedTimes(ResourceListChanged::class, 1);
    Event::assertDispatched(
        ResourceListChanged::class,
        static function (ResourceListChanged $event) use ($channel, $otherChannel): bool {
            $channels = array_map(strval(...), $event->broadcastOn());

            return ($event->broadcastWith() === []) &&
                in_array('private-' . $channel, $channels, true) &&
                (! in_array('private-' . $otherChannel, $channels, true));
        },
    );

    RecordChanged::make($draft)
        ->event('retrieved')
        ->dispatch();

    Event::assertDispatchedTimes(ResourceListChanged::class, 1);

    Filament::setCurrentPanel($panel);
    Filament::setTenant($tenant);

    $otherPage->call('refreshList', $channel, $otherFingerprint);

    expect($otherPage->effects)->not->toHaveKey('html');

    $this->actingAs($reader);

    $page->call('refreshList', $channel, $fingerprint)
        ->assertSee('Private draft revision');
});

it('routes updates in strict mode and notifies the original tenant when its key was loaded', function (
    array $columns,
    array $transferRecipients,
    ModelEvent $event,
): void {
    $tenants = [
        'A' => User::factory()->create(),
        'B' => User::factory()->create(),
        'C' => User::factory()->create(),
    ];
    $post = Post::factory()->create(['author_id' => $tenants['A']->getKey()]);

    $panel = Filament::getPanel('tenancy')
        ->tenant(User::class, ownershipRelationship: 'author');

    Filament::setCurrentPanel($panel);
    PostResource::registerTenancyModelGlobalScope($panel);

    FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([$event]);

    $channels = [];

    foreach ($tenants as $label => $tenant) {
        Filament::setTenant($tenant);

        $channels[$label] = 'private-' . livewire(ListPosts::class)
            ->instance()
            ->getListUpdateChannel();
    }

    $record = Post::withoutGlobalScopes()
        ->select($columns)
        ->findOrFail($post->getKey());

    $wasStrict = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();

    try {
        DB::beginTransaction();

        try {
            (clone $record)->update(['author_id' => $tenants['C']->getKey()]);
        } finally {
            DB::rollBack();
        }

        $record->update(['title' => 'Rename in A']);

        DB::transaction(function () use ($record, $tenants): void {
            $record->update(['author_id' => $tenants['B']->getKey()]);
            $record->update(['title' => 'Rename in B']);

            Event::assertDispatchedTimes(ResourceListChanged::class, 1);
        });
    } finally {
        Model::preventAccessingMissingAttributes($wasStrict);
    }

    $recipients = Event::dispatched(ResourceListChanged::class)
        ->pluck(0)
        ->map(static function (ResourceListChanged $event) use ($channels): array {
            $destinations = array_map(strval(...), $event->broadcastOn());

            return array_keys(array_intersect($channels, $destinations));
        });

    expect($recipients->all())->toBe([['A'], $transferRecipients, ['B']]);
})
    ->with([
        'tenant key not selected' => [['id', 'title'], ['B']],
        'tenant key selected' => [['id', 'title', 'author_id'], ['A', 'B']],
    ])
    ->with([ModelEvent::Updated, ModelEvent::Saved]);

it('checks the effective subscription and current resource access on every refresh and signing request', function (
    string $method,
    ?string $argument,
): void {
    $page = livewire(ListTickets::class);

    expect(
        $page->instance()
            ->getListUpdateChannel(),
    )->toBeNull();

    FilamentRealtime::subscribe(Ticket::class);

    $channel = $page->instance()
        ->getListUpdateChannel();

    expect($channel)->not->toBeNull();

    app()->bind(TicketPolicy::class . '::viewAny', static fn (): bool => false);

    $page->call($method, $channel, $argument)
        ->assertForbidden();
})->with([
    ['authorizeListUpdates', '12.34'],
    ['refreshList', null],
]);

it('does not fall back to a model-wide channel when tenant ownership cannot be inferred', function (): void {
    FilamentRealtime::subscribe(Post::class);

    $globalChannel = livewire(ListPosts::class)
        ->instance()
        ->getListUpdateChannel();

    expect($globalChannel)->not->toBeNull();

    Filament::setCurrentPanel(Filament::getPanel('tenancy'));
    Filament::setTenant(Team::factory()->create()); // Posts belong to a team through their author.

    $page = livewire(ListPosts::class);

    expect(
        $page->instance()
            ->getListUpdateChannel(),
    )->toBeNull();

    $page->call('authorizeListUpdates', $globalChannel, '12.34')
        ->assertForbidden();
});

it('leaves inline-editable lists alone', function (): void {
    FilamentRealtime::subscribe(Post::class);

    expect(
        livewire(ListPostsWithEditableColumn::class)
            ->instance()
            ->getListUpdateChannel(),
    )->toBeNull();
});

function enableListUpdateBrowserSignals(): void
{
    Artisan::call('filament:assets');

    // Drive Echo delivery explicitly. Signing, Livewire transport and the table are real.
    FilamentView::registerRenderHook(PanelsRenderHook::SCRIPTS_AFTER, static fn (): HtmlString => new HtmlString(<<<'HTML'
        <script>
            window.EchoFactory = class {
                constructor(configuration) {
                    this.configuration = configuration
                }

                private(channelName) {
                    const delivery = window.listTestDelivery = {}
                    const channel = {
                        listen(event, callback) {
                            if (event === '.filament.list-changed') delivery.notify = callback

                            return channel
                        },
                        subscribed: callback => {
                            delivery.subscribe = () => this.configuration.channelAuthorization.customHandler(
                                { socketId: '123.456', channelName },
                                (error, authorization) => {
                                    if (error || !authorization?.auth) return

                                    delivery.subscribed = true
                                    callback()
                                },
                            )

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
}

it('updates eager and lazy inactive tab counts without changing the active list', function (): void {
    enableListUpdateBrowserSignals();
    FilamentRealtime::subscribe(Post::class);

    $published = Post::factory()->create(['is_published' => true]);
    $draft = Post::factory()->create(['is_published' => false, 'rating' => 0]);

    Route::get('/live-post-counts', ListPostsWithTabCounts::class)
        ->middleware(Filament::getPanel('admin')->getMiddleware());

    $page = visit('/live-post-counts');

    $page->assertScript('window.listTestDelivery?.subscribed', true)
        ->assertSeeIn('[data-tab-key=draft]', '1')
        ->assertSeeIn('[data-tab-key=lazy]', '0');

    $draft->update(['rating' => 5]);
    $page->script('window.listTestDelivery.notify({}); undefined');

    $page->assertSeeIn('[data-tab-key=lazy]', '1')
        ->assertSeeIn('[data-tab-key=draft]', '1');

    Post::factory()->create(['is_published' => false, 'rating' => 0]);
    $page->script('window.listTestDelivery.notify({}); undefined');

    $page->assertSeeIn('[data-tab-key=draft]', '2')
        ->assertSeeIn('[data-tab-key=lazy]', '1')
        ->assertSee($published->title)
        ->assertDontSee($draft->title)
        ->assertNoAccessibilityIssues();

    $darkPage = $page->inDarkMode();

    $darkPage->assertSeeIn('[data-tab-key=lazy]', '1')
        ->assertNoAccessibilityIssues();
});

it('resumes list updates after a deferred filter is changed back without applying', function (): void {
    enableListUpdateBrowserSignals();
    FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([ModelEvent::Updated]);

    $post = Post::factory()->create(['title' => 'Original title']);

    Route::get('/live-post-filters', ListPostsWithDeferredFilters::class)
        ->middleware(Filament::getPanel('admin')->getMiddleware());

    $page = visit('/live-post-filters');
    $page->assertScript('window.listTestDelivery?.subscribed', true);

    $select = 'select[id="tableFiltersForm.trashed.value"]';

    $page->click('button[aria-label="Filter"]')
        ->select($select, '1')
        ->assertSelected($select, '1');

    $page->select($select, '')
        ->assertSelected($select, '')
        ->assertNoAccessibilityIssues();

    $page->click('h1:first-of-type');

    $post->update(['title' => 'Revised after reverting the filter']);
    $page->script('window.listTestDelivery.notify({}); undefined');

    $page->assertSee('Revised after reverting the filter');

    $page->inDarkMode()
        ->click('button[aria-label="Filter"]')
        ->assertNoAccessibilityIssues();
});

it('applies in-flight updates when idle and catches up after deselection and resubscription', function (): void {
    enableListUpdateBrowserSignals();
    FilamentRealtime::subscribe(Post::class)
        ->onlyEvents([ModelEvent::Updated]);

    $post = Post::factory()->create(['title' => 'Original title']);

    $page = visit(PostResource::getUrl())
        ->assertScript('window.listTestDelivery?.subscribed', true);

    $checkbox = 'tbody input[type="checkbox"][value="' . $post->getKey() . '"]';

    $page->check($checkbox)
        ->assertChecked($checkbox);

    $post->update(['title' => 'Revised while selected']);
    $page->script('window.listTestDelivery.notify({}); undefined');

    $page->assertSee('Original title')
        ->assertChecked($checkbox);

    $page->uncheck($checkbox)
        ->assertSee('Revised while selected');

    $page->check($checkbox)
        ->assertChecked($checkbox);

    $post->update(['title' => 'Changed while disconnected']);
    $page->script('window.listTestDelivery.subscribe(); undefined');

    $page->assertNoAccessibilityIssues()
        ->assertSee('Revised while selected')
        ->assertChecked($checkbox);

    $page->uncheck($checkbox)
        ->assertSee('Changed while disconnected');

    $holdListUpdate = <<<'JS'
        window.releaseListResponse = null
        window.listResponseFinished = false

        const stop = Livewire.interceptMessage(({ onFinish }) => {
            stop()
            onFinish(() => window.listResponseFinished = true)
        })

        const originalFetch = window.fetch
        window.fetch = async (...parameters) => {
            window.fetch = originalFetch
            const response = await originalFetch(...parameters)
            await new Promise(resolve => window.releaseListResponse = resolve)

            return response
        }

        void window.listTestDelivery.notify({})
        JS;

    $search = 'main input[type="search"]';

    $page->fill($search, 'Revision')
        ->assertNotPresent($checkbox);

    $post->update(['title' => 'Revision in flight']);
    $page->script($holdListUpdate);

    $page->assertScript('typeof window.releaseListResponse', 'function')
        ->click($search);

    // Retrying because of the click would lose the matching row in the held response.
    $post->delete();
    $page->script('window.releaseListResponse()');

    $page->assertSee('Revision in flight')
        ->assertPresent($search . ':focus')
        ->assertValue($search, 'Revision');

    $page->script($holdListUpdate);

    $page->assertScript('typeof window.releaseListResponse', 'function')
        ->check($checkbox);

    $page->script('window.releaseListResponse()');

    $page->assertScript('window.listResponseFinished')
        ->assertSee('Revision in flight')
        ->assertChecked($checkbox);

    $page->uncheck($checkbox)
        ->assertNotPresent($checkbox);

    $post->restore();

    $darkPage = visit(PostResource::getUrl())
        ->inDarkMode()
        ->assertScript('window.listTestDelivery?.subscribed', true);

    $darkPage->check($checkbox)
        ->assertChecked($checkbox)
        ->assertNoAccessibilityIssues();
});

class ListPostsWithEditableColumn extends ListPosts
{
    public function table(Table $table): Table
    {
        return $table->columns([TextInputColumn::make('title')]);
    }
}

class ListPostsWithSummary extends ListPosts
{
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title'),
            TextColumn::make('rating')
                ->summarize(Sum::make()->money('USD')),
        ]);
    }
}

class ListPostsWithDeferredFilters extends ListPosts
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->filters([TrashedFilter::make()])
            ->deferFilters();
    }
}

class ListPostsWithTabCounts extends ListPostsWithTabs
{
    public function getTabs(): array
    {
        $tabs = parent::getTabs();

        $drafts = static fn (ListPostsWithTabs $livewire): Builder => $livewire->getResource()::getEloquentQuery()
            ->where('is_published', false);

        $tabs['draft']->badge(static fn (ListPostsWithTabs $livewire): int => $drafts($livewire)->count());

        $tabs['lazy'] = Tab::make('Top-rated drafts')
            ->badge(static fn (ListPostsWithTabs $livewire): int => $drafts($livewire)
                ->where('rating', 5)
                ->count())
            ->lazyLoadedBadge();

        $tabs['hidden'] = Tab::make('Hidden')
            ->hidden()
            ->badge(static fn () => throw new LogicException('A hidden badge was evaluated.'));

        return $tabs;
    }
}
