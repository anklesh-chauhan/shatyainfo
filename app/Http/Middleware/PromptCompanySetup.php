<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Onboarding;
use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PromptCompanySetup
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && Tenant::checkCurrent()
            && $request->user()?->hasAnyRole(['super_admin', 'administration_admin'])
            && ! $request->routeIs(Onboarding::getRouteName(Filament::getPanel('admin')))
            && ! $request->expectsJson()) {
            if (! Tenant::current()->accountIsConfigured()) {
                return redirect(Onboarding::getUrl(panel: 'admin'));
            }
            $setup = app(CompanyOnboardingService::class)->organization()?->metadata['company_setup'] ?? [];
            if ($request->routeIs('filament.admin.pages.dashboard') && ! ($setup['completed'] ?? false) && ! ($setup['deferred'] ?? false)
                && ! (app(CompanyOnboardingService::class)->draft()['deferred'] ?? false)) {
                return redirect(Onboarding::getUrl(panel: 'admin'));
            }
        }

        return $next($request);
    }
}
