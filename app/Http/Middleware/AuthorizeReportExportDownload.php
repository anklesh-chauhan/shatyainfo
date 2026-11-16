<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Policies\ReportExportPolicy;
use Closure;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeReportExportDownload
{
    public function handle(Request $request, Closure $next): Response
    {
        $export = $request->route('export');
        $policy = app(ReportExportPolicy::class);

        if (! $request->routeIs('filament.exports.download') || ! $export instanceof Export || ! $policy->isReport($export)) {
            return $next($request);
        }

        $guard = $request->hasValidSignature(absolute: false) ? $request->query('authGuard') : null;
        $user = auth($guard)->user();
        abort_unless($user instanceof User, 401);
        abort_unless($policy->view($user, $export), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
