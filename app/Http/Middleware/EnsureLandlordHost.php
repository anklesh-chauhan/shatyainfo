<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\LandlordDomains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLandlordHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Tenant::checkCurrent() || ! LandlordDomains::isLandlordHost($request->getHost())) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
