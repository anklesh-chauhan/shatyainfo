<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Onboarding;
use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use Filament\Widgets\Widget;

class CompanySetupReminder extends Widget
{
    protected string $view = 'filament.widgets.company-setup-reminder';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Onboarding::canAccess()
            && ! session()->get(static::reminderSessionKey(), false)
            && auth()->user()->company_setup_reminder_dismissed_at === null
            && ! (app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['completed'] ?? false);
    }

    public function remindLater(): void
    {
        abort_unless(Onboarding::canAccess(), 403);

        session()->put(static::reminderSessionKey(), true);
    }

    protected static function reminderSessionKey(): string
    {
        return 'company_setup_reminder_later.'.Tenant::current()?->getKey().'.'.auth()->id();
    }

    public function dismiss(): void
    {
        abort_unless(Onboarding::canAccess(), 403);

        auth()->user()->forceFill([
            'company_setup_reminder_dismissed_at' => now(),
        ])->save();
    }
}
