<?php

use App\Enums\ApprovalActivityAction;
use App\Events\ApprovalStatusChanged;
use App\Models\ApprovalActivity;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\LeaveApplication;
use App\Models\Position;
use App\Models\SalesTourPlan;
use App\Models\Territory;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function approvalUser(string $name, JobRole $jobRole, Territory $territory): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
    ]);

    $employee = Employee::query()->create([
        'employee_id' => fake()->unique()->numerify('EMP###'),
        'first_name' => $name,
        'last_name' => 'User',
        'email' => $user->email,
        'mobile_number' => fake()->unique()->numerify('90000#####'),
        'login_id' => $user->id,
        'is_active' => true,
    ]);

    $position = Position::query()->create([
        'name' => "{$name} Position",
        'code' => strtoupper(fake()->unique()->bothify('POS###??')),
        'job_role_id' => $jobRole->id,
    ]);

    $position->territories()->attach($territory->id);
    $employee->positions()->attach($position->id, ['is_primary' => true]);

    return $user;
}

function approvalFixture(array $enabledModules = ['SalesTourPlan']): array
{
    ApprovalSetting::query()->update(['enabled_modules' => $enabledModules]);

    $territory = Territory::query()->create([
        'name' => 'North',
        'code' => fake()->unique()->bothify('T###'),
    ]);

    $requesterRole = JobRole::query()->create(['name' => 'Representative', 'code' => 'REP', 'level' => 1]);
    $managerRole = JobRole::query()->create(['name' => 'Manager', 'code' => 'MGR', 'level' => 2]);
    $directorRole = JobRole::query()->create(['name' => 'Director', 'code' => 'DIR', 'level' => 3]);

    $requester = approvalUser('Requester', $requesterRole, $territory);
    $manager = approvalUser('Manager', $managerRole, $territory);
    $director = approvalUser('Director', $directorRole, $territory);

    $record = SalesTourPlan::query()->create(['user_id' => $requester->id, 'month' => '2026-10-01']);

    return compact('record', 'territory', 'requesterRole', 'managerRole', 'directorRole', 'requester', 'manager', 'director');
}

test('it resolves the highest priority effective approval flow and snapshots the request', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    ApprovalFlow::query()->create([
        'module' => 'SalesTourPlan',
        'territory_id' => $fixture['territory']->id,
        'min_amount' => 0,
        'max_amount' => 1000,
        'priority' => 1,
        'version' => 1,
        'effective_from' => now()->subDay(),
        'effective_to' => now()->addDay(),
    ]);

    $selectedFlow = ApprovalFlow::query()->create([
        'module' => 'SalesTourPlan',
        'territory_id' => $fixture['territory']->id,
        'min_amount' => 0,
        'max_amount' => 1000,
        'priority' => 10,
        'version' => 2,
        'effective_from' => now()->subDay(),
        'effective_to' => now()->addDay(),
    ]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $selectedFlow->id,
        'step_order' => 1,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ]);

    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 500);

    expect($approval->approval_flow_id)->toBe($selectedFlow->id)
        ->and($approval->flow_version)->toBe(2)
        ->and($approval->module)->toBe('SalesTourPlan')
        ->and($approval->record_type)->toBe(SalesTourPlan::class)
        ->and((float) $approval->requested_amount)->toBe(500.0)
        ->and($approval->selected_steps)->toHaveCount(1)
        ->and($approval->selected_approvers)->toHaveCount(1)
        ->and($approval->submitted_record_summary['id'])->toBe($fixture['record']->id);
});

test('it approves sequentially and finalizes once', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);

    foreach ([[$fixture['managerRole'], 1], [$fixture['directorRole'], 2]] as [$role, $order]) {
        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'step_order' => $order,
            'job_role_id' => $role->id,
            'territory_scope' => 'self',
        ]);
    }

    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);

    expect(app(ApprovalService::class)->approve($approval, $fixture['director']->id))->toBeFalse();
    expect(app(ApprovalService::class)->approve($approval, $fixture['manager']->id))->toBeTrue();
    expect($approval->refresh()->approval_status)->toBe('pending');
    expect(app(ApprovalService::class)->approve($approval, $fixture['director']->id))->toBeTrue();
    expect($approval->refresh()->approval_status)->toBe('approved')
        ->and($approval->finalized_at)->not->toBeNull();

    expect($approval->finalized_at)->not->toBeNull();
});

test('it rejects the current step and writes immutable activity', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);
    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ]);

    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);

    expect(app(ApprovalService::class)->reject($approval, $fixture['manager']->id, 'Not enough detail'))->toBeTrue()
        ->and($approval->refresh()->approval_status)->toBe('rejected');

    expect(ApprovalActivity::query()->where('approval_id', $approval->id)->where('action', ApprovalActivityAction::Rejected->value)->exists())->toBeTrue();
});

test('it skips optional steps without an approver and keeps later actionable steps', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $missingRole = JobRole::query()->create(['name' => 'Missing', 'code' => 'MISS', 'level' => 4]);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $missingRole->id,
        'territory_scope' => 'self',
        'can_skip' => true,
    ]);
    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 2,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ]);

    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);

    expect($approval->steps)->toHaveCount(1)
        ->and($approval->steps->first()->assigned_user_id)->toBe($fixture['manager']->id)
        ->and(ApprovalActivity::query()->where('approval_id', $approval->id)->where('action', ApprovalActivityAction::Skipped->value)->exists())->toBeTrue();
});

test('it fails when a required approver cannot be resolved', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $missingRole = JobRole::query()->create(['name' => 'Missing', 'code' => 'MISS', 'level' => 4]);
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);

    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $missingRole->id,
        'territory_scope' => 'self',
        'can_skip' => false,
    ]);

    app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);
})->throws(LogicException::class, 'No approver found');

test('it fails when approval is disabled for the module', function () {
    $fixture = approvalFixture([]);
    $this->actingAs($fixture['requester']);

    app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);
})->throws(LogicException::class, 'Approval is not enabled');

test('it prevents duplicate active approval requests', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);
    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ]);

    app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);
    app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);
})->throws(LogicException::class, 'Approval already exists');

test('it treats a second approval attempt as idempotent after finalization', function () {
    Event::fake([ApprovalStatusChanged::class]);
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);

    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'territory_id' => $fixture['territory']->id]);
    ApprovalFlowStep::query()->create([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ]);

    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id, 100);

    expect(app(ApprovalService::class)->approve($approval, $fixture['manager']->id))->toBeTrue();
    expect(app(ApprovalService::class)->approve($approval, $fixture['manager']->id))->toBeFalse();
    expect(ApprovalActivity::query()->where('approval_id', $approval->id)->where('action', ApprovalActivityAction::Approved->value)->count())->toBe(1);
});

test('only modules with workflow support and an outcome handler are eligible', function () {
    expect(ApprovalSetting::eligibleModuleOptions())->toHaveKey(SalesTourPlan::class)
        ->toHaveKey(LeaveApplication::class)
        ->not->toHaveKey(Territory::class)
        ->not->toHaveKey(User::class);
    expect(fn () => ApprovalSetting::instance()->update(['enabled_modules' => [Territory::class]]))
        ->toThrow(ValidationException::class);
});

test('supported module names are normalized and invalid legacy modules cannot start approval', function () {
    $fixture = approvalFixture();
    ApprovalSetting::instance()->update(['enabled_modules' => ['SalesTourPlan', SalesTourPlan::class]]);
    expect(ApprovalSetting::enabledModules())->toBe([SalesTourPlan::class]);
    ApprovalSetting::query()->update(['enabled_modules' => ['Territory']]);
    $this->actingAs($fixture['requester']);
    expect(fn () => app(ApprovalService::class)->start($fixture['territory'], 'Territory', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'Unsupported approval module');
});

function approvalSingleStep(array $fixture, array $attributes = []): ApprovalFlow
{
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan']);
    ApprovalFlowStep::query()->create(array_merge([
        'approval_flow_id' => $flow->id,
        'step_order' => 1,
        'job_role_id' => $fixture['managerRole']->id,
        'territory_scope' => 'self',
    ], $attributes));

    return $flow;
}

test('ambiguous approvers fail without leaving partial approval records', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    approvalUser('Other Manager', $fixture['managerRole'], $fixture['territory']);
    approvalSingleStep($fixture, ['can_skip' => true]);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'Multiple approvers match');
    $this->assertDatabaseCount('approvals', 0);
    $this->assertDatabaseCount('approval_steps', 0);
    $this->assertDatabaseCount('approval_activities', 0);
});

test('role and territory must belong to the same position', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    $otherTerritory = Territory::query()->create(['name' => 'South', 'code' => 'SOUTH']);
    $fixture['manager']->employee->positions->first()->territories()->sync([$otherTerritory->id]);
    $position = Position::query()->create(['name' => 'Unrelated Position', 'code' => 'UNRELATED', 'job_role_id' => $fixture['directorRole']->id]);
    $position->territories()->attach($fixture['territory']->id);
    $fixture['manager']->employee->positions()->attach($position->id);
    approvalSingleStep($fixture);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'No approver found');
});

test('inactive employees cannot be assigned as approvers', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    $fixture['manager']->employee->update(['is_active' => false]);
    approvalSingleStep($fixture);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'No approver found');
});

test('missing requester positions and higher seniority do not skip required steps', function (bool $missingPosition) {
    $fixture = approvalFixture();
    $requester = $missingPosition ? User::factory()->create() : $fixture['director'];
    $this->actingAs($requester);
    approvalSingleStep($fixture);
    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id);
    expect($approval->approval_status)->toBe('pending')
        ->and($approval->steps)->toHaveCount(1)
        ->and($approval->steps->first()->assigned_user_id)->toBe($fixture['manager']->id);
})->with([true, false]);

test('empty and fully skipped flows never auto approve', function (bool $optionalStep) {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    if ($optionalStep) {
        $missingRole = JobRole::query()->create(['name' => 'Unavailable', 'code' => 'UNAV', 'level' => 4]);
        approvalSingleStep($fixture, ['job_role_id' => $missingRole->id, 'can_skip' => true]);
    } else {
        ApprovalFlow::query()->create(['module' => 'SalesTourPlan']);
    }
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'No actionable approval steps');
    $this->assertDatabaseCount('approvals', 0);
})->with([true, false]);

test('a requester cannot become their own approver through delegation', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    approvalSingleStep($fixture);
    ApprovalDelegation::query()->create([
        'delegator_user_id' => $fixture['manager']->id,
        'delegate_user_id' => $fixture['requester']->id,
        'module' => 'SalesTourPlan',
        'active' => true,
    ]);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'The requester cannot approve');
});

test('territory scoped steps require a territory while all territories is explicit', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    $flow = approvalSingleStep($fixture);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan'))
        ->toThrow(LogicException::class, 'A territory is required');
    $flow->steps()->update(['territory_scope' => 'all']);
    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan');
    expect($approval->steps->first()->assigned_user_id)->toBe($fixture['manager']->id);
});

test('a single active delegation routes to the replacement approver', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    approvalSingleStep($fixture);
    ApprovalDelegation::query()->create([
        'delegator_user_id' => $fixture['manager']->id,
        'delegate_user_id' => $fixture['director']->id,
        'module' => 'SalesTourPlan',
        'active' => true,
    ]);
    $approval = app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id);
    expect($approval->steps->first()->assigned_user_id)->toBe($fixture['director']->id)
        ->and($approval->steps->first()->reassigned_from_user_id)->toBe($fixture['manager']->id);
});

test('overlapping delegations cannot silently select a replacement', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['requester']);
    approvalSingleStep($fixture);
    foreach ([null, 'SalesTourPlan'] as $module) {
        ApprovalDelegation::query()->create([
            'delegator_user_id' => $fixture['manager']->id,
            'delegate_user_id' => $fixture['director']->id,
            'module' => $module,
            'active' => true,
        ]);
    }
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'Multiple active delegations');
});

test('the requester is excluded from direct approver selection', function () {
    $fixture = approvalFixture();
    $this->actingAs($fixture['manager']);
    approvalSingleStep($fixture);
    expect(fn () => app(ApprovalService::class)->start($fixture['record'], 'SalesTourPlan', $fixture['territory']->id))
        ->toThrow(LogicException::class, 'No approver found');
});
