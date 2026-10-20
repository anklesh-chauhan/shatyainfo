<?php

namespace App\Http\Middleware;

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
            if (! Tenant::current()->accountIsConfigured()) {
                return redirect()->route('filament.admin.pages.onboarding');
            }
        }

        return $next($request);
    }
}
