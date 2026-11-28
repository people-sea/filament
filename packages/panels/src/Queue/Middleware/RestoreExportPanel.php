<?php

namespace Filament\Queue\Middleware;

use Closure;
use Filament\Actions\Exports\Jobs\CreateXlsxFile;
use Filament\Actions\Exports\Jobs\ExportCompletion;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Exports\Jobs\PrepareCsvExport;
use Illuminate\Contracts\Auth\Authenticatable;

class RestoreExportPanel extends RestorePanel
{
    public function handle(PrepareCsvExport | ExportCsv | CreateXlsxFile | ExportCompletion $job, Closure $next): mixed
    {
        return $this->handleInPanel(
            static fn (): Authenticatable => $job->getExport()->user,
            static fn (): mixed => $next($job),
        );
    }
}
