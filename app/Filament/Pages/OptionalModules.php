<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GlobalConfiguration\OrganizationAndCompanyCluster;
use App\Services\OptionalModuleService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OptionalModules extends Page
{
    protected static ?string $cluster = OrganizationAndCompanyCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $title = 'Optional modules';

    protected static ?string $navigationLabel = 'Optional Modules';

    protected static ?int $navigationSort = 30;

    public function getSubheading(): ?string
    {
        return 'Turn on inventory, expenses, attendance and CRM once prerequisites are complete.';
    }

    public array $reviewed = [];

    public static function canAccess(): bool
    {
        return Onboarding::canAccess();
    }

    public function activate(string $module): void
    {
        app(OptionalModuleService::class)->activate($module, ($this->reviewed[$module] ?? false) === true);
        Notification::make()->title('Module activated')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['modules' => app(OptionalModuleService::class)->status()];
    }

    protected string $view = 'filament.pages.optional-modules';
}
