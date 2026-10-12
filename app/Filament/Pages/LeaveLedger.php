<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Pages\Reports\LeaveLedger as ReportsLeaveLedger;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class LeaveLedger extends Page
{
    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Leave Ledger';

    protected static ?string $title = 'Leave Ledger';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected string $view = 'filament.pages.leave-ledger';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->redirect(ReportsLeaveLedger::getUrl(), navigate: true);
    }
}
