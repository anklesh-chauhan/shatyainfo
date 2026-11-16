<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectFromUnavailableDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()
            || ! $request->routeIs('filament.admin.pages.dashboard')
            || Dashboard::canAccess()) {
            return $next($request);
        }

        $destination = Filament::getCurrentPanel()?->getRedirectUrl();
        if ($destination && rtrim($destination, '/') !== rtrim($request->url(), '/')) {
            return redirect()->to($destination);
        }

        return $next($request);
    }
}
