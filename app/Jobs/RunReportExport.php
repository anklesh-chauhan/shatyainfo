<?php

namespace App\Jobs;

use App\Models\User;
use App\Policies\ReportExportPolicy;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Illuminate\Auth\Access\AuthorizationException;

class RunReportExport extends ExportCsv
{
    public function handle(): void
    {
        $user = $this->export->user;
        if (! $user instanceof User || ! app(ReportExportPolicy::class)->view($user, $this->export)) {
            $this->fail(new AuthorizationException('The report export is no longer authorized.'));

            return;
        }

        parent::handle();
    }
}
