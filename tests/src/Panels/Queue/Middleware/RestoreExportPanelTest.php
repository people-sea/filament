<?php

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\ExportDispatcher;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Tests\Fixtures\Models\Team;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Resources\Tenancy\TenantScopedUsers\TenantScopedUserResource;
use Filament\Tests\Panels\Pages\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader as CsvReader;
use League\Flysystem\UnableToWriteFile;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

uses(TestCase::class);

$dispatchExport = function (array $formats = [ExportFormat::Xlsx]): array {
    $dispatcher = Bus::getFacadeRoot();
    Bus::fake();

    try {
        app(ExportDispatcher::class)->dispatch(
            export: $this->export,
            serializedQuery: EloquentSerializeFacade::serialize(User::query()->whereKey($this->record)),
            columnMap: ['name' => 'Name'],
            options: [],
            formats: $formats,
            records: null,
            job: PrepareCsvExport::class,
            chunkSize: 1,
            jobQueue: 'exports',
            jobConnection: 'database',
            jobBatchName: null,
            authGuard: 'exports',
        );
        $chain = Bus::dispatched(ChainedBatch::class)->sole();

        return [serialize($chain->jobs->sole()), ...$chain->chained];
    } finally {
        Bus::swap($dispatcher);
    }
};

$runExportJob = function (string $payload): array {
    $job = unserialize($payload);
    $batch = ($job instanceof PrepareCsvExport) ? $job->withFakeBatch()[1] : null;

    app(Pipeline::class)
        ->send($job)
        ->through([...((method_exists($job, 'middleware')) ? $job->middleware() : []), ...$job->middleware])
        ->then(static fn (object $job) => $job->handle());

    return array_map(serialize(...), $batch?->added ?? []);
};

beforeEach(function (): void {
    app()->bind(Authenticatable::class, PanelExportUser::class);
    config()->set('queue.default', 'database');
    Notification::fake();
    $this->disk = Storage::fake('local');
    $this->exportingUser = PanelExportUser::query()->create(User::factory()->raw());
    $this->actingAs($this->exportingUser, 'web');
    config()->set('auth.guards.exports', config('auth.guards.web'));
    auth('exports')->setUser($this->exportingUser);
    $this->tenant = Team::factory()->create();
    $this->exportingUser->teams()->attach($this->tenant);
    $this->record = User::factory()->create(['name' => 'Exported record']);
    $this->record->teams()->attach($this->tenant);
    $this->panel = Panel::make()->id('export-panel')->authGuard('exports')
        ->tenant(Team::class)->resources([TenantScopedUserResource::class]);
    app(PanelRegistry::class)->register($this->panel);
    Filament::setCurrentPanel($this->panel);
    Filament::setTenant($this->tenant);
    $this->panel->bootTenancy();
    $this->export = Export::create([
        'user_id' => $this->exportingUser->getKey(),
        'exporter' => PanelUserExporter::class,
        'file_disk' => 'local',
        'file_name' => 'users',
        'total_rows' => 1,
    ]);
});

it('restores the originating context throughout a serialized export and restores previous context', function (bool $hasTenancy, array $formats) use ($dispatchExport, $runExportJob): void {
    if (! $hasTenancy) {
        $this->panel->tenant(null);
    }

    $following = $dispatchExport->call($this, $formats);
    $prepare = array_shift($following);
    $previousPanel = Filament::getPanel('admin');
    $previousTenant = Team::factory()->create();
    $previousUser = User::factory()->create();
    $previousPanelUser = User::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant, isQuiet: true);
    $this->actingAs($previousUser, 'web');
    auth('exports')->setUser($previousPanelUser);
    $defaultGuard = auth()->guard();
    $panelGuard = auth('exports');
    $previousUserResolver = static fn () => $previousUser;
    auth()->resolveUsersUsing($previousUserResolver);
    $contexts = [];
    $middlewareStages = [];
    Event::listen('export.row', function (array $context) use (&$contexts): void {
        $contexts[] = $context;
    });
    Event::listen('export.middleware', function (string $stage) use (&$middlewareStages): void {
        $middlewareStages[] = [$stage, Filament::getCurrentPanel()?->getId()];
    });

    $chunks = $runExportJob($prepare);
    expect($chunks)->toHaveCount(1);

    foreach ([...$chunks, ...$following] as $payload) {
        $runExportJob($payload);

        expect(Filament::getCurrentPanel())->toBe($previousPanel)
            ->and(Filament::getTenant())->toBe($previousTenant)
            ->and(auth()->getDefaultDriver())->toBe('web')
            ->and(auth()->userResolver())->toBe($previousUserResolver)
            ->and(auth()->guard())->toBe($defaultGuard)
            ->and(auth('exports'))->toBe($panelGuard)
            ->and($defaultGuard->user())->toBe($previousUser)
            ->and($panelGuard->user())->toBe($previousPanelUser);
    }

    expect($this->export->refresh()->successful_rows)->toBe(1)
        ->and($this->export->processed_rows)->toBe(1)
        ->and($this->export->completed_at)->not->toBeNull()
        ->and($contexts)->toBe([['export-panel', $hasTenancy ? $this->tenant->getKey() : null, $this->exportingUser->getKey(), $this->exportingUser->getKey(), 'exports']])
        ->and($middlewareStages)->toBe([
            ['first before', 'admin'],
            ['second before', 'admin'],
            ['second after', 'admin'],
            ['first after', 'admin'],
        ]);
    $directory = $this->export->getFileDirectory();
    expect(array_values(iterator_to_array(CsvReader::fromString($this->disk->get($directory . '/0000000000000001.csv'))->getRecords())))->toBe([['Exported record']]);
    $reader = new XlsxReader;
    $reader->open($this->disk->path($directory . '/users.xlsx'));
    $rows = [];

    try {
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
    } finally {
        $reader->close();
    }

    expect($rows)->toBe([['Name'], ['Exported record']]);
    Notification::assertSentTo($this->exportingUser, DatabaseNotification::class, static fn (DatabaseNotification $notification): bool => $notification->data['iconColor'] === 'success');
})->with(['non-tenant panel' => false, 'tenant panel' => true])
    ->with(['CSV and XLSX' => [[ExportFormat::Csv, ExportFormat::Xlsx]], 'XLSX only' => [[ExportFormat::Xlsx]]]);

it('fails closed at each serialized export stage when access is revoked', function (string $stage, bool $hasTenancy, string $revocation) use ($dispatchExport, $runExportJob): void {
    if (! $hasTenancy) {
        $this->panel->tenant(null);
    }

    [$prepare, $xlsx, $completion] = $dispatchExport->call($this);
    $payload = $prepare;

    if ($stage !== 'preparation') {
        $payload = $runExportJob($prepare)[0];
    }

    if (in_array($stage, ['XLSX', 'completion'])) {
        $runExportJob($payload);
        $payload = $xlsx;
    }

    if ($stage === 'completion') {
        $runExportJob($xlsx);
        $payload = $completion;
    }

    if ($revocation === 'panel') {
        $this->exportingUser->update(['name' => 'Panel access revoked']);
        expect($this->exportingUser->canAccessTenant($this->tenant))->toBeTrue();
    } elseif ($revocation === 'tenant') {
        $this->exportingUser->teams()->detach($this->tenant);
    } else {
        $this->exportingUser->teams()->detach($this->tenant);
        $this->record->teams()->detach($this->tenant);
        $this->tenant->delete();
    }

    $files = $this->disk->allFiles();
    $processedRows = $this->export->refresh()->processed_rows;
    $successfulRows = $this->export->successful_rows;
    Filament::setCurrentPanel(null);
    Filament::setTenant(null, isQuiet: true);
    auth()->forgetGuards();
    $defaultGuard = auth()->guard();
    $panelGuard = auth('exports');

    expect(fn () => $runExportJob($payload))->toThrow($revocation === 'deleted tenant' ? ModelNotFoundException::class : AuthorizationException::class);
    expect($this->disk->allFiles())->toBe($files)
        ->and($this->export->refresh()->processed_rows)->toBe($processedRows)
        ->and($this->export->successful_rows)->toBe($successfulRows)
        ->and($this->export->completed_at)->toBeNull()
        ->and(Filament::getCurrentPanel())->toBeNull()
        ->and(Filament::getTenant())->toBeNull()
        ->and(auth()->getDefaultDriver())->toBe('web')
        ->and($defaultGuard->user())->toBeNull()
        ->and($panelGuard->user())->toBeNull();
    Notification::assertNothingSent();
})->with(['preparation', 'chunk', 'XLSX', 'completion'])->with([
    'non-tenant panel access' => [false, 'panel'],
    'tenant panel access' => [true, 'panel'],
    'tenant access' => [true, 'tenant'],
    'deleted tenant' => [true, 'deleted tenant'],
]);

it('does not apply panel checks to exports started outside a panel even when the worker has a panel', function () use ($dispatchExport, $runExportJob): void {
    Filament::setCurrentPanel(null);
    Filament::setTenant(null, isQuiet: true);
    $this->exportingUser->update(['name' => 'Panel access revoked']);
    [$prepare, $xlsx, $completion] = $dispatchExport->call($this);
    auth()->forgetGuards();

    $previousPanel = Filament::getPanel('tenancy');
    $previousTenant = Team::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant, isQuiet: true);

    foreach ([...$runExportJob($prepare), $xlsx, $completion] as $payload) {
        expect(unserialize($payload)->middleware)->toBe([]);
        $runExportJob($payload);
    }

    expect($this->export->refresh()->successful_rows)->toBe(1)
        ->and($this->export->completed_at)->not->toBeNull()
        ->and(Filament::getCurrentPanel())->toBe($previousPanel)
        ->and(Filament::getTenant())->toBe($previousTenant)
        ->and(auth()->user())->toBeNull()
        ->and(auth('exports')->user())->toBeNull();
});

it('restores panel and authentication context when exporting throws', function () use ($dispatchExport, $runExportJob): void {
    [$prepare] = $dispatchExport->call($this);
    [$chunk] = $runExportJob($prepare);
    $previousPanel = Filament::getPanel('admin');
    $previousTenant = Team::factory()->create();
    $previousUser = User::factory()->create();
    Filament::setCurrentPanel($previousPanel);
    Filament::setTenant($previousTenant, isQuiet: true);
    $this->actingAs($previousUser, 'web');
    auth('exports')->forgetUser();
    $defaultGuard = auth()->guard();
    $panelGuard = auth('exports');
    $previousUserResolver = auth()->userResolver();
    $failingDisk = Mockery::mock($this->disk);
    $failingDisk->shouldReceive('put')->andReturnFalse();
    Storage::set('local', $failingDisk);

    try {
        expect(fn () => $runExportJob($chunk))->toThrow(UnableToWriteFile::class);
        expect(Filament::getCurrentPanel())->toBe($previousPanel)
            ->and(Filament::getTenant())->toBe($previousTenant)
            ->and(auth()->getDefaultDriver())->toBe('web')
            ->and(auth()->userResolver())->toBe($previousUserResolver)
            ->and(auth()->guard())->toBe($defaultGuard)
            ->and(auth('exports'))->toBe($panelGuard)
            ->and($defaultGuard->user())->toBe($previousUser)
            ->and($panelGuard->user())->toBeNull()
            ->and($this->export->refresh()->processed_rows)->toBe(0)
            ->and($this->export->successful_rows)->toBe(0);
    } finally {
        Storage::set('local', $this->disk);
    }
});

it('preserves panel context through the actual queued export chain', function () use ($dispatchExport): void {
    config()->set([
        'queue.connections.database' => ['driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs', 'queue' => 'exports', 'retry_after' => 90],
        'queue.batching.database' => config('database.default'),
    ]);
    $following = $dispatchExport->call($this, [ExportFormat::Csv, ExportFormat::Xlsx]);
    $prepare = unserialize(array_shift($following));
    Bus::chain([
        Bus::batch([$prepare])->allowFailures(),
        ...array_map(unserialize(...), $following),
    ])->onConnection('database')->onQueue('exports')->dispatch();
    Filament::setCurrentPanel(null);
    Filament::setTenant(null, isQuiet: true);
    auth()->forgetGuards();
    $queue = Queue::connection('database');

    while ($job = $queue->pop('exports')) {
        $job->fire();

        expect(Filament::getCurrentPanel())->toBeNull()
            ->and(Filament::getTenant())->toBeNull()
            ->and(auth()->getDefaultDriver())->toBe('web')
            ->and(auth()->user())->toBeNull()
            ->and(auth('exports')->user())->toBeNull();
    }

    expect($queue->size('exports'))->toBe(0)
        ->and($this->export->refresh()->successful_rows)->toBe(1)
        ->and($this->export->completed_at)->not->toBeNull()
        ->and($this->disk->exists($this->export->getFileDirectory() . '/users.xlsx'))->toBeTrue();
    Notification::assertSentTo($this->exportingUser, DatabaseNotification::class);
});

class PanelExportUser extends User
{
    protected $table = 'users';

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ($this->name !== 'Panel access revoked')
            && ($panel->getId() === 'export-panel')
            && (auth()->id() === $this->getKey())
            && (Filament::auth()->id() === $this->getKey());
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->teams()->whereKey($tenant)->exists();
    }
}

class PanelUserExporter extends Exporter
{
    protected static ?string $model = User::class;

    public function getJobMiddleware(): array
    {
        return [
            ...parent::getJobMiddleware(),
            new ObserveExportMiddleware('first'),
            new ObserveExportMiddleware('second'),
        ];
    }

    public static function getColumns(): array
    {
        return [ExportColumn::make('name')->formatStateUsing(static function (string $state): string {
            Event::dispatch('export.row', [[Filament::getCurrentPanel()?->getId(), Filament::getTenant()?->getKey(), auth()->id(), auth('exports')->id(), auth()->getDefaultDriver()]]);

            return $state;
        })];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return "Exported {$export->successful_rows} users";
    }
}

class ObserveExportMiddleware
{
    public function __construct(public string $name) {}

    public function handle(ExportCsv $job, Closure $next): mixed
    {
        Event::dispatch('export.middleware', ["{$this->name} before"]);

        $result = $next($job);

        Event::dispatch('export.middleware', ["{$this->name} after"]);

        return $result;
    }
}
