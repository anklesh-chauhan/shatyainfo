<?php

namespace App\Http\Middleware;

use App\Models\Visit;
use App\Services\OptionalModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFieldModules
{
    public function handle(Request $request, Closure $next): Response
    {
        $journalAction = $request->is('api/v1/field-visit-events') ? $request->input('action') : null;
        if ($request->is('api/v1/visits', 'api/v1/visits/*', 'api/v1/sgip-distributions', 'api/v1/field-visit-events', 'api/v1/tour-plan-details/*/visits', 'api/v1/tour-plan-details/*/visits/action')) {
            $this->authorizeVisit($request);
        }

        if ($journalAction === 'order') {
            abort_unless($request->user()->can('Update:SalesOrder'), 403);
        }

        if ($request->is('api/v1/order-catalog')) {
            $user = $request->user();
            abort_unless($user->can('Update:SalesOrder'), 403);
        }

        if ($request->is('api/v1/visits/*/documents')) {
            $type = $request->input('type');
            if (in_array($type, ['quote', 'sales_order'], true)) {
                abort_unless($request->user()->can($type === 'quote' ? 'Create:Quote' : 'Create:SalesOrder'), 403);
            }
        }

        $resource = match (true) {
            $journalAction === 'order', $request->is('api/v1/leads', 'api/v1/leads/*', 'api/v1/deals', 'api/v1/deals/*', 'api/v1/visits/*/documents', 'api/v1/order-catalog') => 'Lead',
            $journalAction === 'samples', $request->is('api/v1/visits/*/sgip', 'api/v1/visits/*/sgip/*', 'api/v1/sample-ledger', 'api/v1/sgip-distributions') => 'SgipDistribution',
            $request->is('api/v1/attendance', 'api/v1/attendance/*', 'api/v1/leave-types', 'api/v1/leave-applications', 'api/v1/leave-applications/*') => 'DailyAttendance',
            default => null,
        };

        if ($resource !== null) {
            abort_unless(app(OptionalModuleService::class)->navigationEnabled($resource), 403);
        }

        if ($journalAction === 'e_detailing' || $request->is('api/v1/e-detailing', 'api/v1/e-detailing/*', 'api/v1/visits/*/e-detailing', 'api/v1/visits/*/e-detailing/*')) {
            $user = $request->user();
            abort_unless($user->can('ViewAny:EDetailingMaterial'), 403);
            if ($request->is('*/file') || ! $request->isMethodSafe()) {
                abort_unless($user->can('View:EDetailingMaterial'), 403);
            }
        }

        return $next($request);
    }

    private function authorizeVisit(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->can(['ViewAny:Visit', 'View:Visit']), 403);

        if ($request->isMethodSafe()) {
            return;
        }

        $journal = $request->is('api/v1/field-visit-events');
        $visitId = $request->route('visit') ?? ($journal ? $request->input('target.visit_id') : null);

        if ($request->is('api/v1/visits/direct', 'api/v1/tour-plan-details/*/visits', 'api/v1/tour-plan-details/*/visits/action')
            || ($journal && $visitId === null)) {
            abort_unless($user->can('Create:Visit'), 403);
        }

        if (! $request->is('api/v1/visits/direct', 'api/v1/tour-plan-details/*/visits')) {
            abort_unless($user->can('Update:Visit'), 403);
        }

        if (is_numeric($visitId) && $request->hasHeader('Idempotency-Key')) {
            abort_unless(Visit::query()->where('employee_id', $user->id)->whereKey($visitId)->exists(), 404);
        }
    }
}
