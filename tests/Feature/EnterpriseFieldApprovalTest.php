<?php

use App\Enums\FieldPlanningMode;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\SalesDcr;
use App\Models\VisitPreference;
use App\Services\Approval\ApprovalService;
use App\Services\Field\FieldDcrService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('Enterprise MR');
    $this->manager = reportingUser('Enterprise Area Manager');
    $this->director = reportingUser('Enterprise Director');
    $this->outsider = reportingUser('Other Branch Manager');
    foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster', 'Manage:FieldPlanningPreference'] as $name) {
        $this->rep->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $this->actingAs($this->rep);
    $this->doctor = reportingDoctor($this->rep, 'Enterprise doctor');
    $this->root = reportingTerritory('Enterprise root');
    $area = reportingTerritory('Enterprise area');
    $area->update(['parent_territory_id' => $this->root->id]);
    $leaf = reportingTerritory('Enterprise leaf');
    $leaf->update(['parent_territory_id' => $area->id]);
    $outside = reportingTerritory('Other branch');
    $this->roles = [];
    foreach ([[$this->manager, $leaf, 2], [$this->director, $this->root, 3], [$this->outsider, $outside, 2]] as [$user, $territory, $level]) {
        $role = $this->roles[$level] ??= JobRole::query()->create(['name' => 'Enterprise level '.$level, 'code' => 'EL'.$level, 'level' => $level]);
        $position = Position::query()->create(['name' => $user->name, 'code' => 'EP'.$user->id, 'job_role_id' => $role->id]);
        $position->territories()->attach($territory->id);
        $user->employee->positions()->attach($position->id);
    }
    ApprovalSetting::instance()->update(['enabled_modules' => [SalesDcr::class]]);
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false, 'enforce_minimum_duration' => false]);
});

function enterpriseDcr($test, string $mode): SalesDcr
{
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($test->rep, ['mode' => $mode, 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::from($mode)), 'reason' => 'Enterprise approval validation'], 0);
    $settings->activate($test->rep, $revision->id, 1);
    $id = $test->postJson('/api/v1/visits/direct', ['client_call_id' => (string) Str::uuid(), 'company_id' => $test->doctor->id,
        'date' => today()->toDateString(), 'unplanned_reason' => 'Enterprise actual call'])->assertSuccessful()->json('data.id');
    $test->postJson('/api/v1/visits/'.$id.'/check-in', [])->assertSuccessful();
    $test->postJson('/api/v1/visits/'.$id.'/check-out', [])->assertSuccessful();

    return SalesDcr::query()->where('user_id', $test->rep->id)->sole();
}

function enterpriseFlow($test, string $scope): void
{
    $flow = ApprovalFlow::query()->create(['module' => 'SalesDcr', 'active' => true]);
    foreach ([[$test->manager, 2, $scope], [$test->director, 3, 'all']] as $index => [$user, $level, $stepScope]) {
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => $index + 1,
            'job_role_id' => $test->roles[$level]->id, 'territory_scope' => $stepScope,
            'approver_user_id' => $stepScope === 'all' ? $user->id : null]);
    }
}

it('routes lightweight DCRs through sequential enterprise levels and retains locks', function (string $mode, string $scope) {
    $dcr = enterpriseDcr($this, $mode);
    if ($scope === 'children') {
        $dcr->update(['territory_id' => $this->root->id]);
    }
    enterpriseFlow($this, $scope);
    app(FieldDcrService::class)->submit($this->rep, $dcr->fresh());
    $approval = $dcr->fresh()->approval;
    expect($approval->steps()->orderBy('step_order')->pluck('assigned_user_id')->all())->toBe([$this->manager->id, $this->director->id]);
    $service = app(ApprovalService::class);
    expect($service->approve($approval, $this->outsider->id))->toBeFalse()
        ->and($service->approve($approval, $this->director->id))->toBeFalse();
    expect(fn () => $dcr->fresh()->update(['route' => 'Bypass attempt']))->toThrow(ValidationException::class);
    expect($service->approve($approval, $this->manager->id))->toBeTrue()
        ->and($dcr->fresh()->approval_status)->toBe('submitted');
    expect($service->approve($approval, $this->director->id))->toBeTrue()
        ->and($dcr->fresh()->approval_status)->toBe('approved')
        ->and($service->approve($approval, $this->director->id))->toBeFalse();
    expect(fn () => $dcr->fresh()->update(['route' => 'Approved edit']))->toThrow(ValidationException::class);
})->with(['direct_dcr', 'flexible'])->with(['all', 'children']);

it('fails closed for territory-scoped routing on a patchless DCR without partial approval', function () {
    $dcr = enterpriseDcr($this, 'direct_dcr');
    enterpriseFlow($this, 'children');
    expect(fn () => app(FieldDcrService::class)->submit($this->rep, $dcr))->toThrow(ValidationException::class);
    expect($dcr->fresh()->approval_status)->toBe('draft')->and($dcr->approval()->count())->toBe(0);
});

it('retains rejected DCR history and delegates the final stage on resubmission', function () {
    $dcr = enterpriseDcr($this, 'direct_dcr');
    enterpriseFlow($this, 'all');
    $service = app(ApprovalService::class);
    app(FieldDcrService::class)->submit($this->rep, $dcr);
    $first = $dcr->fresh()->approval;
    expect($service->reject($first, $this->manager->id, 'Correct route'))->toBeTrue()
        ->and($dcr->fresh()->approval_status)->toBe('rejected');
    $dcr->refresh()->update(['route' => 'Corrected route']);
    ApprovalDelegation::query()->create(['delegator_user_id' => $this->director->id, 'delegate_user_id' => $this->outsider->id,
        'module' => 'SalesDcr', 'active' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'reason' => 'Director test absence']);
    app(FieldDcrService::class)->submit($this->rep, $dcr->fresh());
    $second = $dcr->fresh()->approval;
    expect($second->id)->not->toBe($first->id)->and($first->fresh()->approval_status)->toBe('rejected');
    expect($service->approve($second, $this->manager->id))->toBeTrue()
        ->and($service->approve($second, $this->director->id))->toBeFalse()
        ->and($service->approve($second, $this->outsider->id))->toBeTrue()
        ->and($dcr->fresh()->approval_status)->toBe('approved');
});
