<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\LandlordDomains;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantModel = app(IsTenant::class);
        $host = LandlordDomains::normalize($request->getHost());

        if (LandlordDomains::isLandlordHost($host)) {
            $tenant = $this->tenantFromHeader($request, $tenantModel);

            if (! $tenant) {
                $tenantModel::forgetCurrent();

                return $next($request);
            }
        } else {
            $tenant = $tenantModel::query()->where('domain', $host)->first();

            if (! $tenant) {
                abort(Response::HTTP_NOT_FOUND, 'Tenant not found');
            }
        }

        if ($tenant->getAttribute('provisioning_status') !== 'ready') {
            $tenantModel::forgetCurrent();

            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Company setup is not complete. Please try again later.');
        }

        $tenant->makeCurrent();

        $path = trim($request->path(), '/');
        if ($path === 'landlord' || str_starts_with($path, 'landlord/')) {
            abort(Response::HTTP_FORBIDDEN, 'Landlord routes are not available for a company domain.');
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(IsTenant::class)::forgetCurrent();
    }

    private function tenantFromHeader(Request $request, IsTenant $tenantModel): ?IsTenant
    {
        $header = $request->headers->get('X-Tenant-Domain');
        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $domain = LandlordDomains::normalize($header);
        if ($domain === '' || LandlordDomains::isLandlordHost($domain)) {
            abort(Response::HTTP_NOT_FOUND, 'Tenant not found');
        }

        $tenant = $tenantModel::query()->where('domain', $domain)->first();
        if (! $tenant) {
            abort(Response::HTTP_NOT_FOUND, 'Tenant not found');
        }

        return $tenant;
    }
}
