<?php

namespace Filament\Queue\Middleware;

use Closure;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Illuminate\Contracts\Auth\Authenticatable;

class RestoreImportPanel extends RestorePanel
{
    public function handle(ImportCsv $job, Closure $next): mixed
    {
        return $this->handleInPanel(
            static fn (): Authenticatable => $job->getImport()->user,
            static fn (): mixed => $next($job),
        );
    }
}
