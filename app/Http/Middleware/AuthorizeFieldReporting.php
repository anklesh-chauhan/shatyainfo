<?php

namespace App\Http\Middleware;

use App\Models\SalesDcr;
use App\Services\OptionalModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFieldReporting
{
    public function handle(Request $request, Closure $next): Response
    {
        $isExpense = $request->is('api/v1/dcrs/*/expenses', 'api/v1/dcrs/*/expenses/*', 'api/v1/field-expense-events', 'api/v1/dcr-expenses', 'api/v1/expense-types');
        $isReport = $request->is('api/v1/dcrs/*', 'api/v1/field-dcr-events');

        if (! $isExpense && ! $isReport) {
            return $next($request);
        }

        $user = $request->user();
        abort_unless($user->can('ViewAny:SalesDcr') && $user->can('View:SalesDcr'), 403);

        if ($isExpense) {
            abort_unless(app(OptionalModuleService::class)->navigationEnabled('SalesDcrExpense'), 403);
            $action = match (true) {
                $request->is('api/v1/dcrs/*/expenses/*/receipts/*') => 'View',
                $request->isMethodSafe() => 'ViewAny',
                $request->isMethod('DELETE') => 'Delete',
                $request->isMethod('PATCH'), $request->is('api/v1/dcrs/*/expenses/*/receipt') => 'Update',
                default => 'Create',
            };
            abort_unless($user->can($action.':SalesDcrExpense'), 403);
        } elseif (! $request->isMethodSafe()) {
            abort_unless($user->can('Update:SalesDcr'), 403);
        }

        if ($request->route('dcr') !== null && $request->hasHeader('Idempotency-Key')) {
            abort_unless(SalesDcr::query()->where('user_id', $user->id)->whereKey($request->route('dcr'))->exists(), 404);
        }

        return $next($request);
    }
}
