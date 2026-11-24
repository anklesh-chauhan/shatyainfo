<?php

namespace App\Http\Middleware;

use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Services\Field\SalesPerformanceService;
use App\Services\OptionalModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFieldReporting
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/sales-targets') && $request->isMethod('POST') && $request->hasHeader('Idempotency-Key')) {
            $actor = $request->user();
            abort_unless($actor->can('Manage:SalesTargets'), 403);
            $territoryId = filter_var($request->input('territory_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($territoryId === false, 422, 'Choose an accessible territory.');
            abort_unless(app(SalesPerformanceService::class)->territories($actor)->whereKey($territoryId)->exists(), 404);
        }

        if ($request->is('api/v1/territory-sales-assignments') && $request->isMethod('POST') && $request->hasHeader('Idempotency-Key')) {
            $actor = $request->user();
            abort_unless($actor->can('Manage:SalesTargets'), 403);
            $territoryId = filter_var($request->input('territory_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($territoryId === false, 422, 'Choose an accessible territory.');
            abort_unless(app(SalesPerformanceService::class)->territories($actor)->whereKey($territoryId)->exists(), 404);
        }

        if ($request->is('api/v1/travel-segments') && $request->isMethod('POST') && $request->hasHeader('Idempotency-Key')) {
            $actor = $request->user();
            abort_unless($actor->can(['ViewAny:TravelSegment', 'View:TravelSegment', 'Create:TravelSegment']), 403);
            $reportId = filter_var($request->input('sales_dcr_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($reportId === false, 422, 'Choose an accessible daily call report.');
            abort_unless(SalesDcr::query()->applyVisibility('SalesDcr', $actor)->whereKey($reportId)->exists(), 404);
        }

        $isRegister = $request->is('api/v1/dcr-expenses/*')
            || ($request->is('api/v1/dcr-expenses') && (! $request->isMethodSafe() || $request->boolean('register')));
        $isExpense = $isRegister || $request->is('api/v1/dcrs/*/expenses', 'api/v1/dcrs/*/expenses/*', 'api/v1/field-expense-events', 'api/v1/dcr-expenses', 'api/v1/expense-types');
        $isReport = $request->is('api/v1/dcrs', 'api/v1/dcrs/*', 'api/v1/field-dcr-events');

        if (! $isExpense && ! $isReport) {
            return $next($request);
        }

        $user = $request->user();
        abort_unless($user->can('ViewAny:SalesDcr') && $user->can('View:SalesDcr'), 403);

        if ($isExpense) {
            abort_unless(app(OptionalModuleService::class)->navigationEnabled('SalesDcrExpense'), 403);
            if ($isRegister) {
                if ($request->is('api/v1/dcr-expenses') && $request->isMethod('POST') && $request->hasHeader('Idempotency-Key')) {
                    abort_unless($user->can(['ViewAny:SalesDcrExpense', 'View:SalesDcrExpense', 'Create:SalesDcrExpense']), 403);
                    $report = filter_var($request->input('sales_dcr_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $scope = $request->input('scope');
                    abort_unless($report !== false && in_array($scope, ['personal', 'team'], true), 422, 'Choose a daily call report.');
                    $parents = SalesDcr::query()->whereKey($report);
                    abort_unless(($scope === 'team'
                        ? $parents->applyVisibility('SalesDcr', $user)->where('user_id', '!=', $user->id)
                        : $parents->where('user_id', $user->id))->exists(), 404);
                }

                return $next($request);
            }
            $action = match (true) {
                $request->is('api/v1/dcrs/*/expenses/*/receipts/*') => 'View',
                $request->isMethodSafe() => 'ViewAny',
                $request->isMethod('DELETE') => 'Delete',
                $request->isMethod('PATCH'), $request->is('api/v1/dcrs/*/expenses/*/receipt') => 'Update',
                default => 'Create',
            };
            abort_unless($user->can($action.':SalesDcrExpense'), 403);
        } elseif (! $request->isMethodSafe() && ! $request->is('api/v1/dcrs/register-actions')) {
            abort_unless($user->can('Update:SalesDcr'), 403);
        }

        if ($request->route('dcr') !== null && $request->hasHeader('Idempotency-Key')) {
            abort_unless(SalesDcr::query()->where('user_id', $user->id)->whereKey($request->route('dcr'))->exists(), 404);
            if ($isExpense && $request->route('expense') !== null) {
                abort_unless(SalesDcrExpense::withTrashed()->where('sales_dcr_id', $request->route('dcr'))
                    ->whereKey($request->route('expense'))->exists(), 404);
            }
        }

        return $next($request);
    }
}
