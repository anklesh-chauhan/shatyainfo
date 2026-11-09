<?php

use App\Models\FieldUserHq;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SalesDcr;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('launches one HQ and completes its first reporting day', function (string $mode) {
    expect(Organization::query()->count())->toBe(0);
    $admin = reportingUser('Fresh Company Administrator');
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($admin);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $company = app(CompanyOnboardingService::class);
    $company->saveOrganization(['name' => 'Fresh Pharma', 'email' => 'admin@fresh.test',
        'phone' => '9876543210', 'timezone' => 'Asia/Kolkata']);
    $rep = reportingUser('Fresh HQ Representative');
    $unready = reportingUser('Second HQ Representative');
    foreach (['Create:Visit', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
        $rep->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $doctor = reportingDoctor($rep, 'Fresh HQ Doctor');
    FieldUserHq::query()->create(['user_id' => $rep->id,
        'location_master_id' => reportingLocation('Fresh HQ')->id, 'updated_by' => $admin->id]);
    $territory = reportingTerritory('Fresh Territory');
    $patch = reportingPatch($territory, 'Fresh Patch');
    $patch->companies()->attach($doctor->id);
    $role = JobRole::query()->create(['name' => 'Fresh MR', 'code' => 'FRESH-MR', 'level' => 1]);
    $position = Position::query()->create(['name' => 'Fresh MR', 'code' => 'FRESH-POS', 'job_role_id' => $role->id]);
    $position->territories()->attach($territory->id);
    $rep->employee->positions()->attach($position->id, ['is_primary' => true]);
    $launch = app(CompanyLaunchService::class);
    $launch->saveWorkflow($mode, 0);
    app(OnboardingApprovalService::class)->saveDraft(['required' => 'no']);
    $revision = $launch->proposal($company->organization());
    expect($launch->readiness([$unready->employee->id])['ready_count'])->toBe(0);
    $launch->launch([$rep->employee->id], $revision->id);
    $launch->launch([$rep->employee->id], $revision->id);
    expect($launch->history())->toHaveCount(1)
        ->and($launch->forUser($unready)['launched'])->toBeFalse();
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false, 'enforce_minimum_duration' => false]);
    $this->actingAs($rep);
    $this->postJson('/api/v1/login', ['email' => $rep->email, 'password' => 'password',
        'device_name' => 'fresh-company-acceptance'])->assertSuccessful()
        ->assertJsonPath('user.company_launch.launched', true)
        ->assertJsonPath('user.field_planning.mode', $mode);
    $this->getJson('/api/v1/me')->assertSuccessful()
        ->assertJsonPath('data.company_launch.launched', true)
        ->assertJsonPath('data.field_planning.mode', $mode);
    if ($mode === 'formal_mtp') {
        $plan = SalesTourPlan::withoutEvents(fn () => SalesTourPlan::query()->create([
            'user_id' => $rep->id, 'month' => today()->format('Y-m'), 'approval_status' => 'approved',
        ]));
        $detail = SalesTourPlanDetail::withoutEvents(fn () => SalesTourPlanDetail::query()->create([
            'sales_tour_plan_id' => $plan->id, 'date' => today(), 'territory_id' => $territory->id,
        ]));
        $detail->patches()->attach($patch->id);
        $url = '/api/v1/tour-plan-details/'.$detail->id.'/visits';
        $payload = ['patch_id' => $patch->id, 'company_id' => $doctor->id];
    } else {
        $url = '/api/v1/visits/direct';
        $payload = ['client_call_id' => (string) Str::uuid(), 'company_id' => $doctor->id,
            'date' => today()->toDateString(), 'unplanned_reason' => 'Doctor requested a first-day call'];
    }
    $id = $this->postJson($url, $payload)->assertSuccessful()->json('data.id');
    $this->postJson($url, $payload)->assertSuccessful()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/visits/'.$id.'/check-in', [])->assertSuccessful();
    $this->postJson('/api/v1/visits/'.$id.'/check-out', [])->assertSuccessful();
    $dcr = SalesDcr::query()->where('user_id', $rep->id)->sole();
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertSuccessful();
    expect($dcr->fresh()->approval_status)->toBe('approved')
        ->and(Visit::query()->count())->toBe(1);
    $this->actingAs($unready);
    $this->getJson('/api/v1/dcrs/'.$dcr->id)->assertNotFound();
})->with(['direct_dcr', 'flexible', 'formal_mtp']);
