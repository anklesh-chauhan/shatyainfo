<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Field\FieldCoverageService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class FieldCoverage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 70;

    protected static string|\UnitEnum|null $navigationGroup = 'Field Operations';

    protected string $view = 'filament.pages.field-coverage';

    protected static ?string $navigationLabel = 'Customer Coverage';

    protected static ?string $title = 'Customer coverage and frequency';

    public string $month = '';

    public int $userId;

    public static function canAccess(): bool
    {
        return FieldTeamOnboardingService::canManage(auth()->user()) || (bool) auth()->user()?->can('ViewAny:AccountMaster');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = today()->format('Y-m');
        $this->userId = auth()->id();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['report' => app(FieldCoverageService::class)->forMonth(auth()->user(), $this->userId, $this->month),
            'users' => FieldTeamOnboardingService::canManage(auth()->user()) ? User::query()->orderBy('name')->get(['id', 'name']) : collect([auth()->user()])];
    }
}
