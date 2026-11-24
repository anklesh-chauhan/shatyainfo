<?php

namespace App\Http\Middleware;

use App\Models\Visit;
use App\Services\HR\EmployeeRegister;
use App\Services\HR\PositionRegister;
use App\Services\Inventory\InvoiceDeliveryHandoff;
use App\Services\Inventory\LocationRegister;
use App\Services\OptionalModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFieldModules
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/positions', 'api/v1/positions/*')) {
            $actor = $request->user();
            $positions = app(PositionRegister::class);
            $positions->authorize($actor);
            if (! $request->isMethodSafe()) {
                $action = $request->route('action');
                $permission = $request->is('api/v1/positions') ? 'Create' : ($action === 'delete' ? ($request->route('position') ? 'Delete' : 'DeleteAny') : 'Update');
                abort_unless($actor->can($permission.':Position'), 403);
                if ($request->route('position') !== null) {
                    $positions->find($actor, (int) $request->route('position'));
                }
            }
        }

        if ($request->is('api/v1/employees', 'api/v1/employees/*')) {
            $actor = $request->user();
            $employees = app(EmployeeRegister::class);
            $employees->authorize($actor);
            if (! $request->isMethodSafe()) {
                $action = $request->route('action');
                $permission = $request->is('api/v1/employees') ? 'Create' : match ($action) {
                    'delete' => $request->route('employee') ? 'Delete' : 'DeleteAny',
                    'restore' => $request->route('employee') ? 'Restore' : 'RestoreAny',
                    default => 'Update',
                };
                abort_unless($actor->can($permission.':Employee'), 403);
                if ($request->boolean('provision_login')) {
                    abort_unless($actor->can('Create:User'), 403);
                }
                if ($request->route('createKind') === 'grades') {
                    abort_unless($actor->can('Create:EmpGrade'), 403);
                }
                if ($request->route('employee')) {
                    $employees->find($actor, (int) $request->route('employee'));
                }
                if ($request->route('section')) {
                    abort_unless($employees->canReadSection($actor, $request->route('section')), 403);
                    $model = class_basename(EmployeeRegister::SECTIONS[$request->route('section')][1]);
                    $ability = $request->route('rowAction') ? 'DeleteAny' : ($request->route('section') === 'positions' ? 'Update' : ($request->isMethod('POST') ? 'Create' : ($request->isMethod('DELETE') ? 'Delete' : 'Update')));
                    abort_unless($actor->can($ability.':'.$model), 403);
                }
                if ($request->route('changeType')) {
                    abort_unless($actor->can(['ViewAny:HrChangeRequest', 'View:HrChangeRequest', 'Create:HrChangeRequest']), 403);
                }
            }
        }

        if ($request->is('api/v1/location-masters', 'api/v1/location-masters/*')) {
            $actor = $request->user();
            $locations = app(LocationRegister::class);
            $locations->authorize($actor);
            if (! $request->isMethodSafe()) {
                $action = $request->route('action');
                $address = $request->is('api/v1/location-masters/*/addresses', 'api/v1/location-masters/*/addresses/*');
                $permission = $request->is('api/v1/location-masters') ? 'Create'
                    : ($action === 'delete' ? ($request->route('location') ? 'Delete' : 'DeleteAny') : 'Update');
                abort_unless($actor->can($permission.':LocationMaster'), 403);
                if ($address) {
                    $permission = $request->isMethod('DELETE') ? 'Delete' : ($request->isMethod('POST') ? 'Create' : 'Update');
                    abort_unless($actor->can(['ViewAny:Address', 'View:Address', $permission.':Address']), 403);
                }
                if ($request->route('location') !== null) {
                    abort_unless($locations->visible($actor)->withTrashed()->whereKey($request->route('location'))->exists(), 404);
                }
            }
        }

        $journalAction = $request->is('api/v1/field-visit-events') ? $request->input('action') : null;
        if ($request->is('api/v1/visits', 'api/v1/visits/*', 'api/v1/field-visit-events', 'api/v1/tour-plan-details/*/visits', 'api/v1/tour-plan-details/*/visits/action')) {
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

        if ($request->is('api/v1/sales-orders') && $request->isMethod('POST')) {
            abort_unless($request->user()->can(['ViewAny:SalesOrder', 'View:SalesOrder', 'Create:SalesOrder']), 403);
        }

        if ($request->is('api/v1/sales-invoices') && $request->isMethod('POST')) {
            abort_unless($request->user()->can(['ViewAny:SalesInvoice', 'View:SalesInvoice', 'Create:SalesInvoice']), 403);
        }

        if ($request->is('api/v1/sales-invoices/*/delivery-challans') && $request->isMethod('POST')) {
            $handoff = app(InvoiceDeliveryHandoff::class);
            $handoff->authorizeCreate($request->user());
            $handoff->visibleInvoices($request->user())->whereKey($request->route('documentId'))->firstOrFail();
        }

        $resource = match (true) {
            $request->is('api/v1/lead-activities', 'api/v1/lead-activities/*') => 'LeadActivity',
            $request->is('api/v1/quotes', 'api/v1/quotes/*') => 'Quote',
            $request->is('api/v1/sales-orders', 'api/v1/sales-orders/*') => 'SalesOrder',
            $request->is('api/v1/sales-invoices', 'api/v1/sales-invoices/*') => 'SalesInvoice',
            $request->is('api/v1/customer-prices', 'api/v1/customer-prices/*') => 'CustomerPrice',
            $journalAction === 'order', $request->is('api/v1/leads', 'api/v1/leads/*', 'api/v1/deals', 'api/v1/deals/*', 'api/v1/visits/*/documents', 'api/v1/order-catalog') => 'Lead',
            $journalAction === 'samples', $request->is('api/v1/visits/*/sgip', 'api/v1/visits/*/sgip/*', 'api/v1/sample-ledger', 'api/v1/sgip-distributions', 'api/v1/sgip-distributions/*') => 'SgipDistribution',
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

        if ($request->isMethodSafe() || $request->is('api/v1/visits/register-actions')) {
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
