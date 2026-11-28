<?php

use Filament\Actions\Imports\Events\ImportChunkProcessed;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\ImportDispatcher;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Queue\Middleware\RestoreImportTenant;
use Filament\Tests\Fixtures\Models\Team;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Resources\Tenancy\TenantScopedUsers\TenantScopedUserResource;
use Filament\Tests\Panels\Pages\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(TestCase::class);
/**
 * @param  array<array<string, mixed>>  $rows
 * @param  class-string<Importer>  $importer
 */
$tenantImportJob = function (array $rows, string $importer = TenantUserImporter::class): string {
    $import = Import::create([
        'user_id' => auth()->id(),
        'file_name' => 'users.csv',
        'file_path' => 'users.csv',
        'importer' => $importer,
        'total_rows' => count($rows),
    ]);
    $dispatcher = Bus::getFacadeRoot();
    Bus::fake();

    try {
        app(ImportDispatcher::class)->dispatch($import, [$rows], ['name' => 'name', 'email' => 'email'], [], ImportCsv::class, 'web');

        return serialize(Bus::batched(static fn (): bool => true)->sole()->jobs->sole());
    } finally {
        Bus::swap($dispatcher);
    }
};

beforeEach(function (): void {
    app()->bind(Authenticatable::class, TenantImportUser::class);
    $this->importingUser = TenantImportUser::query()->create(User::factory()->raw());
    $this->actingAs($this->importingUser);
    Filament::setCurrentPanel(Filament::getPanel('tenancy'));
    Filament::setTenant(null);
});

it('rechecks serialized imports against their originating panel and restores context', function (bool $hasTenancy, bool $revokePanelAccess) use ($tenantImportJob): void {
    config()->set('auth.guards.imports', config('auth.guards.web'));
    $panel = Panel::make()->id('import-panel')->authGuard('imports');

    if ($hasTenancy) {
        $panel->tenant(Team::class)->resources([TenantScopedUserResource::class]);
    }

    app(PanelRegistry::class)->register($panel);
    Filament::setCurrentPanel($panel);
    auth('imports')->setUser($this->importingUser);
    $tenant = $hasTenancy ? Team::factory()->create() : null;

    if ($tenant) {
        $this->importingUser->teams()->attach($tenant);
        Filament::setTenant($tenant);
    }

    $record = User::factory()->create(['name' => 'Original']);

    if ($tenant) {
        $record->teams()->attach($tenant);
    }

    $job = $tenantImportJob([
        ['id' => $record->getKey(), 'name' => 'Updated'],
        ['name' => 'Created', 'email' => 'panel-import@example.com'],
    ]);

    if ($revokePanelAccess) {
        $this->importingUser->update(['name' => 'Panel access revoked']);
    }

    $previousPanel = Filament::getPanel('admin');
    $previousTenant = Team::factory()->create();
    $previousUser = User::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant);
    $this->actingAs($previousUser, 'web');
    auth('imports')->forgetUser();
    $defaultGuard = auth()->guard();
    $panelGuard = auth('imports');
    $previousUserResolver = static fn () => $previousUser;
    auth()->resolveUsersUsing($previousUserResolver);
    $contexts = [];
    Event::listen(ImportChunkProcessed::class, function () use (&$contexts): void {
        $contexts[] = [Filament::getCurrentPanel()->getId(), Filament::getTenant()?->getKey(), auth()->id(), Filament::auth()->id(), auth()->getDefaultDriver()];
    });

    if ($revokePanelAccess) {
        expect(fn () => Bus::dispatchSync(unserialize($job)))->toThrow(AuthorizationException::class);
    } else {
        Bus::dispatchSync(unserialize($job));
    }

    expect($record->refresh()->name)->toBe($revokePanelAccess ? 'Original' : 'Updated')
        ->and(User::query()->where('email', 'panel-import@example.com')->exists())->toBe(! $revokePanelAccess)
        ->and($contexts)->toBe($revokePanelAccess ? [] : [['import-panel', $tenant?->getKey(), $this->importingUser->getKey(), $this->importingUser->getKey(), 'imports']])
        ->and(Filament::getCurrentPanel())->toBe($previousPanel)
        ->and(Filament::getTenant())->toBe($previousTenant)
        ->and(auth()->getDefaultDriver())->toBe('web')
        ->and(auth()->userResolver())->toBe($previousUserResolver)
        ->and(auth()->id())->toBe($previousUser->getKey())
        ->and($defaultGuard->user())->toBe($previousUser)
        ->and($panelGuard->user())->toBeNull();

    if ($tenant) {
        expect($this->importingUser->canAccessTenant($tenant))->toBeTrue();
    }
})->with(['non-tenant panel' => false, 'tenant panel' => true])
    ->with(['allowed panel access' => false, 'revoked panel access' => true]);

it('only allows panel imports by users without `FilamentUser` in the local environment', function (string $environment) use ($tenantImportJob): void {
    config()->set('app.env', $environment);
    app()->bind(Authenticatable::class, PlainImportUser::class);
    $this->actingAs(PlainImportUser::query()->findOrFail($this->importingUser->getKey()));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $job = $tenantImportJob([['name' => 'Created', 'email' => 'plain-import@example.com']]);
    Filament::setCurrentPanel(null);
    auth()->forgetGuards();

    if ($environment === 'local') {
        Bus::dispatchSync(unserialize($job));
    } else {
        expect(fn () => Bus::dispatchSync(unserialize($job)))->toThrow(AuthorizationException::class);
    }

    expect(User::query()->where('email', 'plain-import@example.com')->exists())->toBe($environment === 'local')
        ->and(Filament::getCurrentPanel())->toBeNull()
        ->and(Filament::getTenant())->toBeNull()
        ->and(auth()->user())->toBeNull();
})->with(['local', 'testing', 'production']);

it('does not attach panel middleware to imports started outside a panel', function () use ($tenantImportJob): void {
    $this->importingUser->update(['name' => 'Panel access revoked']);
    Filament::setCurrentPanel(null);
    $job = $tenantImportJob([['name' => 'Created', 'email' => 'outside-import@example.com']]);
    expect(unserialize($job)->middleware)->toBe([]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Bus::dispatchSync(unserialize($job));

    expect(User::query()->where('email', 'outside-import@example.com')->exists())->toBeTrue()
        ->and(Filament::getCurrentPanel()->getId())->toBe('admin');
});

it('preserves importer middleware ordering outside the restored panel context', function () use ($tenantImportJob): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $job = $tenantImportJob([['name' => 'Created', 'email' => 'middleware-import@example.com']], MiddlewareTenantUserImporter::class);
    Filament::setCurrentPanel(null);
    $contexts = [];
    Event::listen('import.middleware', function (string $stage) use (&$contexts): void {
        $contexts[] = [$stage, Filament::getCurrentPanel()?->getId()];
    });
    Event::listen(ImportChunkProcessed::class, function () use (&$contexts): void {
        $contexts[] = ['processed', Filament::getCurrentPanel()?->getId()];
    });

    Bus::dispatchSync(unserialize($job));

    expect(User::query()->where('email', 'middleware-import@example.com')->exists())->toBeTrue()
        ->and($contexts)->toBe([
            ['first before', null],
            ['second before', null],
            ['processed', 'admin'],
            ['second after', null],
            ['first after', null],
        ]);
});

it('preserves tenant scoping and association across serialized import chunks without leaking context', function (bool $usesLegacyMiddleware) use ($tenantImportJob): void {
    $firstTenant = Team::factory()->create();
    $secondTenant = Team::factory()->create();
    $this->importingUser->teams()->attach([$firstTenant->getKey(), $secondTenant->getKey()]);
    $firstRecord = User::factory()->create(['name' => 'First tenant original']);
    $firstRecord->teams()->attach($firstTenant);
    $secondRecord = User::factory()->create(['name' => 'Second tenant original']);
    $secondRecord->teams()->attach($secondTenant);

    Filament::setTenant($firstTenant);
    $firstJob = $tenantImportJob([
        ['id' => $firstRecord->getKey(), 'name' => 'First tenant updated'],
        ['id' => $secondRecord->getKey(), 'name' => 'Cross-tenant overwrite'],
        ['name' => 'First tenant created', 'email' => 'first-import@example.com'],
    ]);

    if ($usesLegacyMiddleware) {
        $job = unserialize($firstJob);
        $job->through([new RestoreImportTenant(panelId: 'tenancy', tenantId: $firstTenant->getKey())]);
        $firstJob = serialize($job);
    }

    config()->set('auth.guards.imports', config('auth.guards.web'));
    $secondPanel = Panel::make()
        ->id('import-tenancy')
        ->tenant(Team::class)
        ->authGuard('imports')
        ->resources([TenantScopedUserResource::class]);
    app(PanelRegistry::class)->register($secondPanel);
    auth('imports')->setUser($this->importingUser);
    Filament::setCurrentPanel($secondPanel);
    Filament::setTenant($secondTenant);
    $secondJob = $tenantImportJob([
        ['id' => $secondRecord->getKey(), 'name' => 'Second tenant updated'],
        ['name' => 'Second tenant created', 'email' => 'second-import@example.com'],
    ]);

    $previousPanel = Filament::getPanel('admin');
    $previousTenant = Team::factory()->create();
    $previousUser = User::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant);
    $this->actingAs($previousUser);
    auth('imports')->forgetUser();
    $defaultGuard = auth()->guard();
    $panelGuard = auth('imports');
    $contexts = [];
    Event::listen(ImportChunkProcessed::class, function () use (&$contexts): void {
        $contexts[] = [Filament::getCurrentPanel()->getId(), Filament::getTenant()->getKey(), Filament::auth()->id()];
    });

    Bus::dispatchSync(unserialize($firstJob));
    expect($firstRecord->refresh()->name)->toBe('First tenant updated')
        ->and($secondRecord->refresh()->name)->toBe('Second tenant original')
        ->and(User::query()->where('email', 'first-import@example.com')->sole()->teams->modelKeys())->toBe([$firstTenant->getKey()])
        ->and(Filament::getCurrentPanel())->toBe($previousPanel)
        ->and(Filament::getTenant())->toBe($previousTenant)
        ->and(auth()->id())->toBe($previousUser->getKey());

    Bus::dispatchSync(unserialize($secondJob));
    expect($secondRecord->refresh()->name)->toBe('Second tenant updated')
        ->and(User::query()->where('email', 'second-import@example.com')->sole()->teams->modelKeys())->toBe([$secondTenant->getKey()])
        ->and(Filament::getCurrentPanel())->toBe($previousPanel)
        ->and(Filament::getTenant())->toBe($previousTenant)
        ->and(auth()->id())->toBe($previousUser->getKey())
        ->and(auth('imports')->user())->toBeNull()
        ->and($defaultGuard->id())->toBe($previousUser->getKey())
        ->and($panelGuard->user())->toBeNull()
        ->and($contexts)->toBe([
            ['tenancy', $firstTenant->getKey(), $this->importingUser->getKey()],
            ['import-tenancy', $secondTenant->getKey(), $this->importingUser->getKey()],
        ]);
})->with(['panel middleware' => false, 'legacy tenant middleware' => true]);

it('fails closed and restores context when an import tenant is deleted or access is revoked', function (bool $deleteTenant) use ($tenantImportJob): void {
    $tenant = Team::factory()->create();
    $this->importingUser->teams()->attach($tenant);
    $record = User::factory()->create(['name' => 'Original']);
    $record->teams()->attach($tenant);
    Filament::setTenant($tenant);
    $job = $tenantImportJob([['id' => $record->getKey(), 'name' => 'Unauthorized']]);

    if ($deleteTenant) {
        $this->importingUser->teams()->detach($tenant);
        $record->teams()->detach($tenant);
        $tenant->delete();
    } else {
        $this->importingUser->teams()->detach($tenant);
    }

    Filament::setCurrentPanel(null);
    Filament::setTenant(null);
    auth()->forgetGuards();

    expect(fn () => Bus::dispatchSync(unserialize($job)))->toThrow($deleteTenant ? ModelNotFoundException::class : AuthorizationException::class);
    expect($record->refresh()->name)->toBe('Original')
        ->and(Filament::getCurrentPanel())->toBeNull()
        ->and(Filament::getTenant())->toBeNull()
        ->and(auth()->user())->toBeNull();
})->with(['deleted tenant' => true, 'revoked access' => false]);

it('restores context when import processing or an auth restoration listener throws', function (bool $throwsDuringRestoration, bool $hasTenancy) use ($tenantImportJob): void {
    config()->set('auth.guards.imports', config('auth.guards.web'));
    Filament::setCurrentPanel(Filament::getPanel($hasTenancy ? 'tenancy' : 'admin'));
    Filament::getCurrentPanel()->authGuard('imports');
    auth('imports')->setUser($this->importingUser);
    $tenant = Team::factory()->create();
    $this->importingUser->teams()->attach($tenant);
    Filament::setTenant($tenant);
    $job = $tenantImportJob([['name' => 'Created', 'email' => 'failure-import@example.com']]);
    $previousPanel = Filament::getPanel('slugs');
    $previousTenant = Team::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant);
    auth()->forgetGuards();
    $guard = auth()->guard();
    $panelGuard = auth('imports');
    $previousPanelUser = User::factory()->create();
    $panelGuard->setUser($previousPanelUser);
    $previousUserResolver = auth()->userResolver();
    $previousUser = $throwsDuringRestoration ? User::factory()->create() : null;

    if ($previousUser) {
        $guard->setUser($previousUser);
        Event::listen(Authenticated::class, static function (Authenticated $event) use ($previousUser): void {
            if ($event->user->is($previousUser)) {
                throw new RuntimeException('Auth restoration listener failed.');
            }
        });
    }

    Event::listen(ImportChunkProcessed::class, static fn () => throw new RuntimeException('Import listener failed.'));

    expect(fn () => Bus::dispatchSync(unserialize($job)))->toThrow(RuntimeException::class, $throwsDuringRestoration ? 'Auth restoration listener failed.' : 'Import listener failed.');
    expect(Filament::getCurrentPanel())->toBe($previousPanel)
        ->and(Filament::getTenant())->toBe($previousTenant)
        ->and(auth()->getDefaultDriver())->toBe('web')
        ->and(auth()->userResolver())->toBe($previousUserResolver)
        ->and(auth()->id())->toBe($previousUser?->getKey())
        ->and($guard->id())->toBe($previousUser?->getKey())
        ->and($panelGuard->user())->toBe($previousPanelUser);
})->with(['processing failure' => false, 'auth restoration failure' => true])
    ->with(['non-tenant panel' => false, 'tenant panel' => true]);

class TenantUserImporter extends Importer
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [ImportColumn::make('name'), ImportColumn::make('email')];
    }

    public function resolveRecord(): ?Model
    {
        return isset($this->data['id']) ? parent::resolveRecord() : new User(['password' => 'unused']);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return '';
    }
}

class TenantImportUser extends User
{
    protected $table = 'users';

    public function canAccessPanel(Panel $panel): bool
    {
        return ($this->name !== 'Panel access revoked')
            && in_array($panel->getId(), ['admin', 'tenancy', 'import-tenancy', 'import-panel'])
            && (auth()->id() === $this->getKey())
            && (Filament::auth()->id() === $this->getKey());
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id');
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->teams()->whereKey($tenant)->exists();
    }
}

class PlainImportUser extends AuthenticatableUser
{
    protected $table = 'users';
}

class MiddlewareTenantUserImporter extends TenantUserImporter
{
    public function getJobMiddleware(): array
    {
        return [
            ...parent::getJobMiddleware(),
            new ObserveImportMiddleware('first'),
            new ObserveImportMiddleware('second'),
        ];
    }
}

class ObserveImportMiddleware
{
    public function __construct(public string $name) {}

    public function handle(ImportCsv $job, Closure $next): mixed
    {
        Event::dispatch('import.middleware', ["{$this->name} before"]);

        $result = $next($job);

        Event::dispatch('import.middleware', ["{$this->name} after"]);

        return $result;
    }
}
