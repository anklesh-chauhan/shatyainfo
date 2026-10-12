<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\DoctorSampleLedger as ReportsDoctorSampleLedger;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class DoctorSampleLedger extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?string $navigationLabel = 'Doctor Sample Ledger';

    protected static ?string $title = 'Doctor Sample Ledger';

    protected static ?int $navigationSort = 7;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected string $view = 'filament.pages.doctor-sample-ledger';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->redirect(ReportsDoctorSampleLedger::getUrl(), navigate: true);
    }
}
