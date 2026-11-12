<?php

namespace App\Providers\Filament;

use App\Filament\GlobalSearch\BusinessSearchProvider;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyQuickLinks;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Http\Middleware\PromptCompanySetup;
use App\Http\Middleware\RedirectMarketingToPunchIn;
use App\Support\Tenancy\TenantBranding;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Platform;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        FilamentAsset::register([
            Css::make('custom-css', asset('css/filament-custom.css')), // Custom CSS
            // Js::make('sidebar-js', asset('js/sidebar.js')),             // Sidebar JavaScript
        ], true); // ✅ Use `isGlobal` to load for all panels

        return $panel
            ->default()
            ->globalSearch(BusinessSearchProvider::class)
            ->topbar(true)
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn () => view('filament.components.topbar-quick-links'))
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => view('filament.components.subnav-scripts'))
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldSuffix(fn (): string => Platform::detect() === Platform::Mac ? '⌘K' : 'Ctrl+K')
            ->globalSearchDebounce(500)
            ->id('admin')
            ->path('admin')
            ->brandName(fn (): string => TenantBranding::name())
            ->brandLogo(fn () => view('filament.components.tenant-brand'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('shatya-info-logo-transparent.png'))
            ->login()
            ->profile()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->userMenuItems([
                'help' => Action::make('help')->label('Help and guides')->icon('heroicon-o-question-mark-circle')->url(fn (): string => route('help.index')),
                'billing' => Action::make('billing')->label('Billing')->icon('heroicon-o-credit-card')->url(fn (): string => route('billing.index'))->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
                'quick-links' => Action::make('quick-links')->label('My quick links')->icon('heroicon-o-squares-2x2')->url(fn (): string => MyQuickLinks::getUrl()),
                'profile' => Action::make('profile')
                    ->label(fn () => auth()->user()->employee?->full_name ?? auth()->user()->name)
                    ->icon('heroicon-o-user-circle')
                    ->url(fn () => auth()->user()->employee
                        ? EmployeeResource::getUrl('edit', ['record' => auth()->user()->employee->id])
                        : '#'),

                'organization-profile' => Action::make('organization-profile')
                    ->label('Organization Profile')
                    ->url(fn (): string => OrganizationResource::getUrl('edit', ['record' => 1]))
                    ->icon('heroicon-o-building-office')
                    ->visible(fn () => OrganizationResource::canViewAny()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->resources([
                //
            ])
            ->plugins([
                FilamentShieldPlugin::make()->navigationGroup('') ,
            ])
            ->widgets([
                // Widgets displayed in the dashboard
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
                RedirectMarketingToPunchIn::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                PromptCompanySetup::class,
            ])
            ->viteTheme('resources/css/app.css')
            ->navigationGroups([
                NavigationGroup::make()->label('My Workspace')->icon(Heroicon::OutlinedHome),
                NavigationGroup::make()->label('Field Operations')->icon(Heroicon::OutlinedMap)->collapsed(),
                NavigationGroup::make()->label('Customers & Territories')->icon(Heroicon::OutlinedUsers)->collapsed(),
                NavigationGroup::make()->label('Sales & CRM')->icon(Heroicon::OutlinedBriefcase)->collapsed(),
                NavigationGroup::make()->label('Secondary Sales')->icon(Heroicon::OutlinedDocumentChartBar)->collapsed(),
                NavigationGroup::make()->label('Marketing & Samples')->icon(Heroicon::OutlinedMegaphone)->collapsed(),
                NavigationGroup::make()->label('Inventory & Purchasing')->icon(Heroicon::OutlinedCube)->collapsed(),
                NavigationGroup::make()->label('HR & Attendance')->icon(Heroicon::OutlinedUserGroup)->collapsed(),
                NavigationGroup::make()->label('Reports')->icon(Heroicon::OutlinedChartBar)->collapsed(),
                NavigationGroup::make()->label('Settings & Administration')->icon(Heroicon::OutlinedCog6Tooth)->collapsed(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('15rem')
            ->authGuard('web');
    }
}
