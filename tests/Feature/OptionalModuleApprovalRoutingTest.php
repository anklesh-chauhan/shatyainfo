<?php

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\InventoryAdjustment;
use App\Models\JobRole;
use App\Models\LeaveApplication;
use App\Models\Position;
use App\Models\SalesDcr;
use App\Models\SalesOrder;
use App\Services\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('audits a module route without side effects and rejects missing or self approval', function (string $module) {
    $requester = reportingUser('Module Requester');
    $manager = reportingUser('Module Approver');
    $this->actingAs($requester);
    $service = app(ApprovalService::class);
    ApprovalSetting::instance()->update(['enabled_modules' => [$module]]);
    expect(fn () => $service->validateRouteFor($requester, class_basename($module)))->toThrow(LogicException::class);
    $role = JobRole::query()->create(['name' => 'Module Approver', 'code' => 'MOD-APP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Module Approval Position', 'code' => 'MOD-POS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    $requester->employee->positions()->attach($position->id);
    $flow = ApprovalFlow::query()->create(['module' => class_basename($module), 'active' => true]);
    $step = ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1,
        'job_role_id' => $role->id, 'territory_scope' => 'all', 'approver_user_id' => $manager->id]);
    $service->validateRouteFor($requester, class_basename($module));
    expect(Approval::query()->count())->toBe(0);
    $step->update(['approver_user_id' => $requester->id]);
    expect(fn () => $service->validateRouteFor($requester, class_basename($module)))->toThrow(LogicException::class);
    ApprovalSetting::instance()->update(['enabled_modules' => []]);
    $service->validateRouteFor($requester, class_basename($module));
    expect(Approval::query()->count())->toBe(0);
})->with([InventoryAdjustment::class, SalesDcr::class, LeaveApplication::class, SalesOrder::class]);
