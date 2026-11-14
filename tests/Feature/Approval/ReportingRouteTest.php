<?php

use App\Filament\Resources\ApprovalFlows\Pages\CreateApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\ApprovalFlows\Pages\ListApprovalFlows;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\LeaveApplication;
use App\Models\SalesTourPlan;
use App\Services\Approval\ApprovalService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('previews and snapshots a multi-module manager chain using the owner and original approver', function () {
    $admin = reportingUser('Submitting Administrator');
    $owner = reportingUser('Employee Owner');
    $manager = reportingUser('Line Manager');
    $director = reportingUser('Director');
    $delegate = reportingUser('Delegate');
    $owner->employee->update(['explicit_reporting_manager_id' => $manager->employee->id]);
    $manager->employee->update(['explicit_reporting_manager_id' => $director->employee->id]);
    $this->actingAs($admin);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan', 'SalesTourPlanDetail']]);
    $flow = (new ApprovalFlow)->saveConfiguration([
        'name' => 'Field planning', 'modules' => ['SalesTourPlan', 'SalesTourPlanDetail'], 'active' => true,
        'condition_type' => 'unconditional', 'route_type' => 'custom',
        'levels' => [['routing_type' => 'reporting_manager'], ['routing_type' => 'previous_manager']],
    ]);
    ApprovalDelegation::create(['delegator_user_id' => $manager->id, 'delegate_user_id' => $delegate->id, 'active' => true]);
    $record = SalesTourPlan::create(['user_id' => $owner->id, 'month' => today()->startOfMonth()]);
    $service = app(ApprovalService::class);
    $preview = $service->resolveRoute($owner->employee, 'SalesTourPlanDetail');
    $approval = $service->start($record, 'SalesTourPlan');

    expect($preview['flow']->id)->toBe($flow->id)
        ->and(array_map(fn ($step) => $step['assigned']->id, $preview['steps']))->toBe([$delegate->id, $director->id])
        ->and(array_column($approval->selected_approvers, 'user_id'))->toBe([$delegate->id, $director->id])
        ->and($approval->requested_by)->toBe($admin->id);
    $owner->employee->update(['explicit_reporting_manager_id' => $director->employee->id]);
    expect(array_column($approval->fresh()->selected_approvers, 'user_id'))->toBe([$delegate->id, $director->id]);
});

it('rejects duplicate and delegated self-approval', function (string $case) {
    $owner = reportingUser('Field Rep');
    $manager = reportingUser('Line Manager');
    $owner->employee->update(['explicit_reporting_manager_id' => $manager->employee->id]);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan']]);
    (new ApprovalFlow)->saveConfiguration([
        'name' => 'Field planning', 'modules' => ['SalesTourPlan'], 'active' => true, 'route_type' => 'custom',
        'levels' => [['routing_type' => 'reporting_manager'], ['routing_type' => 'specific_employee', 'approver_employee_id' => $manager->employee->id]],
    ]);
    if ($case === 'self') {
        ApprovalDelegation::create(['delegator_user_id' => $manager->id, 'delegate_user_id' => $owner->id, 'active' => true]);
    }

    expect(fn () => app(ApprovalService::class)->resolveRoute($owner->employee, 'SalesTourPlan'))
        ->toThrow(LogicException::class, $case === 'self' ? 'cannot approve requests about themselves' : 'different approver');
})->with(['self', 'duplicate']);

it('rejects overlapping active flows across their shared module and clears unconditional amount limits', function () {
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan', 'SalesTourPlanDetail']]);
    $data = ['name' => 'Planning', 'modules' => ['SalesTourPlan', 'SalesTourPlanDetail'], 'active' => true,
        'condition_type' => 'unconditional', 'min_amount' => 40, 'max_amount' => 60,
        'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'];
    $flow = (new ApprovalFlow)->saveConfiguration($data);
    expect($flow->min_amount)->toBeNull()->and($flow->max_amount)->toBeNull();
    expect(fn () => (new ApprovalFlow)->saveConfiguration([...$data, 'modules' => ['SalesTourPlanDetail']]))
        ->toThrow(ValidationException::class);
    $later = (new ApprovalFlow)->saveConfiguration([...$data, 'modules' => ['SalesTourPlanDetail'], 'effective_from' => '2026-02-01', 'effective_to' => null]);
    expect(ApprovalFlow::query()->matching('SalesTourPlanDetail', null, 500, '2026-01-31')->sole()->id)->toBe($flow->id)
        ->and(ApprovalFlow::query()->matching('SalesTourPlanDetail', null, 500, '2026-02-01')->sole()->id)->toBe($later->id);
});

it('uses the leave employee HR record when an administrator submits', function () {
    $admin = reportingUser('Leave Administrator');
    $owner = reportingUser('Leave Owner');
    $manager = reportingUser('Leave Manager');
    $owner->employee->update(['explicit_reporting_manager_id' => $manager->employee->id]);
    $this->actingAs($admin);
    ApprovalSetting::instance()->update(['enabled_modules' => ['LeaveApplication']]);
    (new ApprovalFlow)->saveConfiguration(['name' => 'Leave', 'modules' => ['LeaveApplication'], 'active' => true]);
    $leave = LeaveApplication::create([
        'employee_id' => $owner->employee->id, 'leave_type_id' => reportingLeaveType('Routed Leave')->id,
        'from_date' => today(), 'to_date' => today(), 'total_days' => 1, 'approval_status' => 'draft',
    ]);
    $approval = app(ApprovalService::class)->start($leave, 'LeaveApplication');
    expect($approval->selected_approvers[0]['user_id'])->toBe($manager->id)
        ->and($approval->submitted_record_summary['employee_id'])->toBe($owner->employee->id);
});

it('reports inactive managers and missing manager logins without assigning an approval', function (bool $inactive) {
    $owner = reportingUser('Field Rep');
    $manager = reportingUser('Manager');
    $owner->employee->update(['explicit_reporting_manager_id' => $manager->employee->id]);
    $manager->employee->provisionLoginOnSave = false;
    $manager->employee->update($inactive ? ['is_active' => false] : ['login_id' => null]);
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan']]);
    (new ApprovalFlow)->saveConfiguration(['name' => 'Field planning', 'modules' => ['SalesTourPlan'], 'active' => true]);
    expect(fn () => app(ApprovalService::class)->resolveRoute($owner->employee->fresh(), 'SalesTourPlan'))
        ->toThrow(LogicException::class, $inactive ? 'inactive' : 'valid login');
    $this->assertDatabaseCount('approvals', 0);
})->with([true, false]);

it('changes a shared flow version only when its routing changes', function () {
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan', 'SalesTourPlanDetail']]);
    $data = ['name' => 'Planning', 'modules' => ['SalesTourPlan', 'SalesTourPlanDetail'], 'active' => true];
    $flow = (new ApprovalFlow)->saveConfiguration($data);
    $flow = $flow->saveConfiguration([...$data, 'name' => 'Field planning']);
    expect($flow->version)->toBe(1);
    $flow = $flow->saveConfiguration([...$data, 'deadline_hours' => 48]);
    expect($flow->version)->toBe(2)->and($flow->steps()->sole()->sla_hours)->toBe(48);
});

it('requires an amount for a bounded flow and allows a disjoint amount range', function () {
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan']]);
    $data = ['name' => 'Planning', 'modules' => ['SalesTourPlan'], 'active' => true, 'condition_type' => 'amount'];
    $first = (new ApprovalFlow)->saveConfiguration([...$data, 'min_amount' => 0, 'max_amount' => 99]);
    $second = (new ApprovalFlow)->saveConfiguration([...$data, 'min_amount' => 100]);
    expect(ApprovalFlow::query()->matching('SalesTourPlan', null, null)->count())->toBe(0)
        ->and(ApprovalFlow::query()->matching('SalesTourPlan', null, 99)->sole()->id)->toBe($first->id)
        ->and(ApprovalFlow::query()->matching('SalesTourPlan', null, 100)->sole()->id)->toBe($second->id);
});

it('creates, edits, previews and duplicates a flow through the Admin resource', function () {
    $admin = reportingUser('Administrator');
    $owner = reportingUser('Employee');
    $manager = reportingUser('Manager');
    $owner->employee->update(['explicit_reporting_manager_id' => $manager->employee->id]);
    $this->actingAs($admin);
    Gate::before(fn (): bool => true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan', 'SalesTourPlanDetail']]);

    Livewire::test(CreateApprovalFlow::class)
        ->fillForm(['name' => 'Planning', 'modules' => ['SalesTourPlan', 'SalesTourPlanDetail']])
        ->call('create')->assertHasNoFormErrors();

    $flow = ApprovalFlow::sole();
    expect($flow->route_type)->toBe('reporting_manager');
    Livewire::test(EditApprovalFlow::class, ['record' => $flow->id])
        ->fillForm(['name' => 'Field planning', 'route_type' => 'custom', 'levels' => [
            ['routing_type' => 'reporting_manager'],
            ['routing_type' => 'specific_employee', 'approver_employee_id' => $admin->employee->id],
        ]])
        ->call('save')->assertHasNoFormErrors();
    expect($flow->fresh()->steps()->count())->toBe(2);
    Livewire::test(ListApprovalFlows::class)
        ->assertSee('Field planning')
        ->callAction('previewRoute', data: ['employee_id' => $owner->employee->id, 'module' => 'SalesTourPlan'])
        ->assertNotified('Field planning')
        ->callAction(TestAction::make('duplicate')->table($flow))
        ->assertNotified('Inactive copy created');
    expect(ApprovalFlow::query()->where('active', false)->sole()->modules)->toBe($flow->modules);
    $this->assertDatabaseCount('approvals', 0);
});

it('denies the Admin approval flow resource to an unauthorized employee', function () {
    $this->actingAs(reportingUser('Unauthorized employee'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListApprovalFlows::class)
        ->assertForbidden();
});
