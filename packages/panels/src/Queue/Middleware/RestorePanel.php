<?php

namespace Filament\Queue\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use Throwable;

/**
 * @internal Never call or extend this class. It is not part of the public API and may change in minor and patch releases.
 */
abstract class RestorePanel
{
    public function __construct(
        protected ?string $panelId,
        protected int | string | null $tenantId = null,
    ) {}

    /**
     * @param  Closure(): Authenticatable  $getUser
     */
    protected function handleInPanel(Closure $getUser, Closure $next): mixed
    {
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        $panel = ($this->panelId !== null)
            ? (Filament::getPanels()[$this->panelId] ?? throw new LogicException("Panel [{$this->panelId}] no longer exists."))
            : null;
        $previousDefaultGuard = auth()->getDefaultDriver();
        $previousUserResolver = auth()->userResolver();
        $guards = [];
        $previousUsers = [];

        foreach ($panel ? array_unique([$previousDefaultGuard, $panel->getAuthGuard()]) : [] as $guardName) {
            $guards[$guardName] = auth()->guard($guardName);
            $previousUsers[$guardName] = $guards[$guardName]->user();

            if ((! $previousUsers[$guardName]) && (! method_exists($guards[$guardName], 'forgetUser'))) {
                throw new LogicException("Auth guard [{$guardName}] must implement [forgetUser()] to restore its guest state after processing the job.");
            }
        }

        try {
            Filament::setTenant(null, isQuiet: true);
            Filament::setCurrentPanel($panel);

            if (! $panel) {
                return $next();
            }

            $user = $getUser();

            foreach ($guards as $guard) {
                $guard->setUser($user);
            }

            auth()->shouldUse($panel->getAuthGuard());

            if ($user instanceof FilamentUser ? (! $user->canAccessPanel($panel)) : (config('app.env') !== 'local')) {
                throw new AuthorizationException;
            }

            if ($this->tenantId !== null) {
                $tenantModel = $panel->getTenantModel() ?? throw new LogicException("Panel [{$this->panelId}] no longer has tenancy enabled.");

                if (! $user instanceof HasTenants) {
                    throw new AuthorizationException;
                }

                $tenant = $tenantModel::query()->findOrFail($this->tenantId);

                if (! $user->canAccessTenant($tenant)) {
                    throw new AuthorizationException;
                }

                $panel->bootTenancy();
                Filament::setTenant($tenant);
            }

            return $next();
        } finally {
            Filament::setTenant($previousTenant, isQuiet: true);
            Filament::setCurrentPanel($previousPanel);
            auth()->setDefaultDriver($previousDefaultGuard);
            auth()->resolveUsersUsing($previousUserResolver);
            $restorationException = null;

            foreach ($previousUsers as $guardName => $user) {
                try {
                    if ($user) {
                        $guards[$guardName]->setUser($user);
                    } else {
                        $guards[$guardName]->forgetUser();
                    }
                } catch (Throwable $exception) {
                    $restorationException ??= $exception;
                }
            }

            if ($restorationException) {
                throw $restorationException;
            }
        }
    }
}
