<?php

namespace App\Filament\Pages;

use App\Jobs\SeedTenantMasters;
use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Onboarding extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Onboarding';

    protected static ?string $title = 'Onboarding';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.onboarding';

    public array $organization = [];

    public function getLayout(): string
    {
        return 'filament-panels::components.layout.simple';
    }

    protected function getLayoutData(): array
    {
        return ['hasTopbar' => false];
    }

    public static function canAccess(): bool
    {
        return Tenant::checkCurrent() && (auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) ?? false);
    }

    public function mount(): void
    {
        if (Tenant::current()->accountIsConfigured()) {
            $this->loadOrganization();
        }
    }

    public function refreshSetup(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        if (Tenant::current()->refresh()->accountIsConfigured() && $this->organization === []) {
            $this->loadOrganization();
        }
    }

    public function retrySetup(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $tenant = Tenant::current();
        $updated = Tenant::query()->whereKey($tenant->id)->where('master_setup_status', 'failed')
            ->update(['master_setup_status' => 'pending', 'master_setup_progress' => 0, 'master_setup_error' => null]);
        if ($updated) {
            SeedTenantMasters::dispatch($tenant->id);
        }
        $tenant->refresh();
    }

    protected function loadOrganization(): void
    {
        $organization = app(CompanyOnboardingService::class)->organization();
        $this->organization = [
            'name' => $organization?->name ?? Tenant::current()?->name,
            'email' => $organization?->email ?? auth()->user()->email,
            'phone' => $organization?->phone ?? '',
        ];
    }

    public function saveOrganization(): void
    {
        $this->validate([
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.email' => ['required', 'email', 'max:255'],
            'organization.phone' => ['required', 'string', 'max:30'],
        ]);
        $service = app(CompanyOnboardingService::class);
        $service->saveOrganization($this->organization);
        $service->mark('completed');
        $this->redirect('/admin');
    }

    public function skip(): void
    {
        app(CompanyOnboardingService::class)->mark('deferred');
        $this->redirect('/admin');
    }
}
