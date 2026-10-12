<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(IsTenant::class)::current();

        if (! $tenant || ! $request->hasSession()) {
            return $next($request);
        }

        $sessionKey = 'tenant_id';
        $sessionTenantId = $request->session()->get($sessionKey);

        if ($sessionTenantId === null) {
            $request->session()->put($sessionKey, $tenant->getKey());

            return $next($request);
        }

        if ((string) $sessionTenantId !== (string) $tenant->getKey()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(Response::HTTP_UNAUTHORIZED, 'Session does not match this company.');
        }

        return $next($request);
    }
}
