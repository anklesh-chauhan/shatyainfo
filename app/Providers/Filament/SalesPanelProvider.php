<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Support\Tenancy\TenantBranding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Platform;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SalesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('sales')
            ->brandName(fn (): string => TenantBranding::name())
            ->brandLogo(fn () => view('filament.components.tenant-brand'))
            ->brandLogoHeight('2rem')
            ->path('sales')
            ->globalSearch(true)
            ->topbar(false)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldSuffix(fn (): string => Platform::detect() === Platform::Mac ? '⌘K' : 'Ctrl+K')
            ->globalSearchDebounce(500)
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Sales/Resources'), for: 'App\Filament\Sales\Resources')
            ->discoverPages(in: app_path('Filament/Sales/Pages'), for: 'App\Filament\Sales\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->resources([
                LeadResource::class,
                OrganizationResource::class,
                DealResource::class,

            ])
            ->discoverWidgets(in: app_path('Filament/Sales/Widgets'), for: 'App\Filament\Sales\Widgets')
            ->widgets([
                //
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                // FilamentShieldPlugin::make(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('14rem')
            ->authGuard('web');
    }
}
