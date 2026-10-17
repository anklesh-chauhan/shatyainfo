<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Tenant;
use Closure;
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
            && ! $request->routeIs('filament.admin.pages.onboarding')
            && ! $request->expectsJson()) {
            $setup = Organization::query()->orderBy('id')->first()?->metadata['company_setup'] ?? [];
            if (! Tenant::current()->accountIsConfigured() || (($setup['pending'] ?? false) && ! ($setup['organization_saved'] ?? false) && ! ($setup['completed'] ?? false) && ! ($setup['deferred'] ?? false))) {
                return redirect()->route('filament.admin.pages.onboarding');
            }
        }

        return $next($request);
    }
}
