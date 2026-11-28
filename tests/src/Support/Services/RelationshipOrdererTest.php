<?php

use Filament\Facades\Filament;
use Filament\Support\Services\RelationshipOrderer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tests\Fixtures\Models\Company;
use Filament\Tests\Fixtures\Models\Post;
use Filament\Tests\Fixtures\Models\PostMetadata;
use Filament\Tests\Fixtures\Models\Profile;
use Filament\Tests\Fixtures\Models\Team;
use Filament\Tests\Fixtures\Models\User;
use Filament\Tests\Fixtures\Resources\Tenancy\TenantScopedUsers\TenantScopedUserResource;
use Filament\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Znck\Eloquent\Relations\BelongsToThrough;

uses(TestCase::class);

beforeEach(function (): void {
    $this->orderer = new RelationshipOrderer;
});

describe('`buildSubquery()`', function (): void {
    it('builds a subquery for a `BelongsTo` relationship', function (): void {
        $query = Post::query();

        $subquery = $this->orderer->buildSubquery($query, 'author', 'name');

        expect($subquery)->toBeInstanceOf(Builder::class);
        expect($subquery->limit)->toBe(1);
    });

    it('builds a subquery selecting the specified column', function (): void {
        $query = Post::query();

        $subquery = $this->orderer->buildSubquery($query, 'author', 'name');

        $columns = $subquery->columns;

        expect($columns)->toHaveCount(1);
        expect($columns[0])->toContain('name');
    });

    it('builds a subquery for a `HasOne` relationship', function (): void {
        $query = User::query();

        $subquery = $this->orderer->buildSubquery($query, 'profile', 'bio');

        expect($subquery)->toBeInstanceOf(Builder::class);
        expect($subquery->limit)->toBe(1);
    });

    it('builds a subquery for a nested relationship', function (): void {
        $user = User::factory()->create();
        Profile::factory()->for($user)->create(['bio' => 'Nested profile']);
        Post::factory()->for($user, 'author')->create();
        $query = Post::query();

        $subquery = $this->orderer->buildSubquery($query, 'author.profile', 'bio');

        expect($query->selectSub($subquery, 'related_value')->sole()->related_value)->toBe('Nested profile');
    });

    it('builds a subquery with `whereColumn` constraints', function (): void {
        $query = Post::query();

        $subquery = $this->orderer->buildSubquery($query, 'author', 'name');

        $wheres = $subquery->wheres;

        // Should have a whereColumn linking the subquery to the parent
        $columnWheres = array_filter($wheres, static fn (array $where): bool => ($where['type'] ?? '') === 'Column');

        expect($columnWheres)->not->toBeEmpty();
    });
});

describe('validation', function (): void {
    it('throws `InvalidArgumentException` for unsupported relationship types', function (): void {
        $query = User::query();

        // `posts()` is a HasMany, which is not supported
        expect(fn () => $this->orderer->buildSubquery($query, 'posts', 'title'))
            ->toThrow(InvalidArgumentException::class, 'Nested sorting only supports');
    });
});

describe('`BelongsToThrough` relationship', function (): void {
    it('builds a subquery for a `BelongsToThrough` relationship', function (): void {
        $query = Post::query();

        $subquery = $this->orderer->buildSubquery($query, 'team', 'name');

        expect($subquery)->toBeInstanceOf(Builder::class);
        expect($subquery->limit)->toBe(1);
    });
});

describe('relationship-defined joins', function (): void {
    it('sorts columns and groups using joined relationship constraints and bindings', function (string $relationship, string $ordering, string $direction): void {
        $users = collect();

        foreach ([['Zulu', 'Approved'], ['Alpha', 'Approved'], ['Hidden', 'Pending']] as [$bio, $companyName]) {
            $user = User::factory()->create();
            $company = Company::factory()->create(['name' => $companyName]);
            Profile::factory()->for($user)->for($company)->create(['bio' => $bio]);
            $users->push($user);
        }

        expect(RelationshipOrdererUser::query()->findOrFail($users[0]->getKey())->{$relationship}->bio)->toBe('Zulu');
        expect(RelationshipOrdererUser::query()->findOrFail($users[2]->getKey())->{$relationship})->toBeNull();

        $query = RelationshipOrdererUser::query();
        $path = "{$relationship}.bio";

        if ($ordering === 'column') {
            TextColumn::make($path)->sortable()->applySort($query, $direction);
        } else {
            Group::make($path)->orderQuery($query, $direction);
        }

        $orderedIds = $query->get()->modelKeys();

        expect(array_values(array_diff($orderedIds, [$users[2]->getKey()])))->toBe($direction === 'asc'
            ? [$users[1]->getKey(), $users[0]->getKey()]
            : [$users[0]->getKey(), $users[1]->getKey()]);
        expect(array_search($users[2]->getKey(), $orderedIds))->toBeIn([0, 2]);
    })->with(['joinedProfile', 'joinedSubqueryProfile', 'latestJoinedProfile'])->with(['column', 'group'])->with(['asc', 'desc']);

    it('preserves explicit joins alongside recreated through joins at intermediate hops', function (string $relationship): void {
        $posts = collect();

        foreach ([['Zulu', 'Approved'], ['Alpha', 'Approved'], ['Hidden', 'Pending']] as [$teamName, $companyName]) {
            $company = Company::factory()->create(['name' => $companyName]);
            $team = Team::factory()->for($company)->create(['name' => $teamName]);
            $user = User::factory()->for($team)->create();
            $posts->push(Post::factory()->for($user, 'author')->create());
        }

        $query = RelationshipOrdererPost::query();
        TextColumn::make("{$relationship}.name")->sortable()->applySort($query, 'asc');
        $orderedIds = $query->get()->modelKeys();

        expect(array_values(array_diff($orderedIds, [$posts[2]->getKey()])))->toBe([$posts[1]->getKey(), $posts[0]->getKey()]);
        expect(array_search($posts[2]->getKey(), $orderedIds))->toBeIn([0, 2]);

        $query = RelationshipOrdererPost::query()->whereKey($posts[2]);
        expect($query->selectSub($this->orderer->buildSubquery($query, "{$relationship}.company", 'name'), 'related_value')
            ->sole()->related_value)->toBeNull();
    })->with(['joinedTeam', 'joinedTeamThroughAuthor']);
});

describe('intermediate relationship scopes', function (): void {
    it('preserves Filament tenancy scopes on intermediate relationships', function (): void {
        $tenant = Team::factory()->create(['name' => 'Visible tenant']);
        $otherTenant = Team::factory()->create(['name' => 'Other tenant']);

        foreach ([$tenant, $otherTenant] as $team) {
            $author = User::factory()->for($team)->create();
            $author->teams()->attach($team);
            Post::factory()->for($author, 'author')->create();
        }

        $originalScopes = Model::getAllGlobalScopes();
        $originalPanel = Filament::getCurrentPanel();
        $originalTenant = Filament::getTenant();

        try {
            $panel = Filament::getPanel('tenancy');
            Filament::setCurrentPanel($panel);
            Filament::setTenant($tenant, isQuiet: true);
            TenantScopedUserResource::registerTenancyModelGlobalScope($panel);
            $query = Post::query();

            expect($query->selectSub($this->orderer->buildSubquery($query, 'author.team', 'name'), 'related_value')
                ->orderBy('posts.id')->get()->pluck('related_value')->all())->toBe(['Visible tenant', null]);
        } finally {
            Model::setAllGlobalScopes($originalScopes);
            Filament::setTenant($originalTenant, isQuiet: true);
            Filament::setCurrentPanel($originalPanel);
        }
    });

    it('preserves intermediate `SoftDeletes` and explicit scope removals', function (string $relationship, ?string $expected): void {
        $user = User::factory()->create();
        $post = Post::factory()->for($user, 'author')->create(['is_published' => true]);
        PostMetadata::factory()->for($post)->create(['seo_title' => 'Deleted']);
        $post->delete();
        $query = RelationshipOrdererUser::query();

        expect($query->selectSub($this->orderer->buildSubquery($query, "{$relationship}.metadata", 'seo_title'), 'related_value')
            ->sole()->related_value)->toBe($expected);
    })->with([['publishedPost', null], ['postWithTrashed', 'Deleted'], ['hiddenPost', null]]);

    it('keeps relationship `orWhere()` constraints within parent correlation', function (): void {
        foreach ([[2, 'First'], [9, 'Second']] as [$rating, $title]) {
            $user = User::factory()->create();
            $post = Post::factory()->for($user, 'author')->create(['rating' => $rating]);
            PostMetadata::factory()->for($post)->create(['seo_title' => $title]);
        }

        $query = RelationshipOrdererUser::query();

        expect($query->selectSub($this->orderer->buildSubquery($query, 'alternativePost.metadata', 'seo_title'), 'related_value')
            ->orderBy('users.id')->get()->pluck('related_value')->all())->toBe(['First', 'Second']);
    });

    it('preserves selected intermediate records without falling back when their target is absent', function (string $relationship, bool $hasTarget): void {
        $user = User::factory()->create();
        $olderPost = Post::factory()->for($user, 'author')->create();
        PostMetadata::factory()->for($olderPost)->create(['seo_title' => 'Not selected']);
        $selectedPost = Post::factory()->for($user, 'author')->create();

        if ($hasTarget) {
            PostMetadata::factory()->for($selectedPost)->create(['seo_title' => 'Selected']);
        }

        $query = RelationshipOrdererUser::query();

        expect($query->selectSub($this->orderer->buildSubquery($query, "{$relationship}.metadata", 'seo_title'), 'related_value')
            ->sole()->related_value)->toBe($hasTarget ? 'Selected' : null);
    })->with(['latestPost', 'orderedPost'])->with([false, true]);

    it('orders table columns and groups using only reachable intermediate records', function (string $ordering, string $direction): void {
        $users = User::factory()->count(3)->create();

        foreach ($users as $index => $user) {
            $post = Post::factory()->for($user, 'author')->create(['is_published' => $index !== 1]);
            PostMetadata::factory()->for($post)->create(['seo_title' => ['Zulu', 'Hidden', 'Alpha'][$index]]);
        }

        $query = User::query();
        $path = 'publishedPost.metadata.seo_title';

        if ($ordering === 'column') {
            TextColumn::make($path)->sortable()->applySort($query, $direction);
        } else {
            Group::make($path)->orderQuery($query, $direction);
        }

        $orderedIds = $query->get()->modelKeys();
        $visibleIds = array_values(array_diff($orderedIds, [$users[1]->getKey()]));

        expect($visibleIds)->toBe($direction === 'asc'
            ? [$users[2]->getKey(), $users[0]->getKey()]
            : [$users[0]->getKey(), $users[2]->getKey()]);
        // Null placement differs across database drivers.
        expect(array_search($users[1]->getKey(), $orderedIds))->toBeIn([0, 2]);
    })->with(['column', 'group'])->with(['asc', 'desc']);
});

class RelationshipOrdererUser extends User
{
    protected $table = 'users';

    public function joinedProfile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id')
            ->join('companies', static fn (JoinClause $join) => $join
                ->on('companies.id', '=', 'profiles.company_id')
                ->where('companies.name', '!=', 'Blocked'))
            ->where('companies.name', 'Approved')
            ->select('profiles.*');
    }

    public function joinedSubqueryProfile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id')
            ->joinSub(DB::table('companies')->where('name', '!=', 'Blocked'), 'approved_companies', static fn (JoinClause $join) => $join
                ->on('approved_companies.id', '=', 'profiles.company_id')
                ->where('approved_companies.name', '!=', 'Suspended'))
            ->where('approved_companies.name', 'Approved')
            ->select('profiles.*');
    }

    public function latestJoinedProfile(): HasOne
    {
        return $this->joinedProfile()->latestOfMany();
    }

    public function postWithTrashed(): HasOne
    {
        return $this->hasOne(Post::class, 'author_id')->withTrashed();
    }

    public function hiddenPost(): HasOne
    {
        return $this->postWithTrashed()->withGlobalScope('visibility', static function (EloquentBuilder $query): void {
            $query->whereRaw('1 = 0');
        });
    }

    public function orderedPost(): HasOne
    {
        return $this->hasOne(Post::class, 'author_id')->orderByDesc('posts.id');
    }

    public function alternativePost(): HasOne
    {
        return $this->hasOne(Post::class, 'author_id')->where('rating', 2)->orWhere('rating', 9)->orderByDesc('posts.id');
    }
}

class RelationshipOrdererPost extends Post
{
    protected $table = 'posts';

    public function joinedTeam(): BelongsToThrough
    {
        return $this->team()
            ->join('companies', static fn (JoinClause $join) => $join
                ->on('companies.id', '=', 'teams.company_id')
                ->where('companies.name', '!=', 'Blocked'))
            ->where('companies.name', 'Approved');
    }

    public function joinedTeamThroughAuthor(): HasOneThrough
    {
        return $this->teamThroughAuthor()
            ->join('companies', static fn (JoinClause $join) => $join
                ->on('companies.id', '=', 'teams.company_id')
                ->where('companies.name', '!=', 'Blocked'))
            ->where('companies.name', 'Approved');
    }
}
