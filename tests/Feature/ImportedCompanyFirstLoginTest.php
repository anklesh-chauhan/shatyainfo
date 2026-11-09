<?php

use App\Models\AccountMaster;
use App\Models\Employee;
use App\Models\SalesDcr;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\VisitPreference;
use App\Services\AccountOnboardingService;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use App\Services\Field\FieldTeamOnboardingService;
use Database\Seeders\AccountReportingMasterSeeder;
use Database\Seeders\AccountStatusSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('imports 26 representatives with manager logins and launches one HQ through the first DCR', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['Create:AccountMaster', 'Update:AccountMaster', 'ViewAny:AccountMaster'] as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($admin);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $company = app(CompanyOnboardingService::class);
    $company->saveOrganization(['name' => 'Imported Pharma', 'email' => 'admin@imported.test',
        'phone' => '9876543210', 'timezone' => 'Asia/Kolkata']);
    $path = tempnam(sys_get_temp_dir(), 'imported-company-');
    try {
        $header = "employee_id,first_name,last_name,mobile_number,email,temporary_password,job_role,position,position_code,is_primary_position,reports_to_position_code,reports_to_employee_code\n";
        $rows = [];
        foreach (range(1, 26) as $index) {
            $rows[] = 'MR'.$index.',Medical,Rep'.$index.',9000000'.sprintf('%03d', $index)
                .',mr'.$index.'@imported.test,Initial-Password42,Medical Representative,MR '.$index.',P'.$index.',Yes,MANAGER,MGR';
        }
        $rows[] = 'MGR,Area,Manager,9000000200,manager@imported.test,Initial-Password42,Area Sales Manager,Area Manager,MANAGER,Yes,,';
        file_put_contents($path, $header.implode("\n", $rows));
        $importer = app(ResourceCsvImporter::class);
        $module = app(ResourceImportCatalog::class)->findByModel(Employee::class);
        expect($importer->previewEmployees($module, $path)['errors'])->toBe([]);
        $import = $importer->import($module, $path);
        expect($import->failed)->toBe([])->and($import->created)->toBe(27);
        expect($importer->import($module, $path)->updated)->toBe(27)
            ->and(User::query()->count())->toBe(28);
        $this->seed([AccountStatusSeeder::class, AccountReportingMasterSeeder::class]);
        TypeMaster::query()->create(['name' => 'Imported Doctor', 'typeable_type' => AccountMaster::class]);
        $accounts = [];
        foreach (range(1, 26) as $index) {
            $accounts[] = ['line' => $index + 1, 'data' => ['external_reference' => 'IMPORTED:'.$index,
                'name' => 'Doctor '.$index, 'account_type' => 'Imported Doctor', 'owner_email' => 'mr'.$index.'@imported.test']];
        }
        $accountService = app(AccountOnboardingService::class);
        expect($accountService->preview($accounts)['errors'])->toBe([]);
        expect($accountService->import($accounts))->toBe(['created' => 26, 'updated' => 0]);
        $hq = reportingLocation('First Imported HQ');
        $employees = Employee::query()->where('employee_id', 'like', 'MR%')->orderBy('id')->get();
        foreach ($employees->take(13) as $employee) {
            app(FieldTeamOnboardingService::class)->save($admin, ['user_id' => $employee->login_id,
                'location_master_id' => $hq->id, 'account_ids' => AccountMaster::query()->where('owner_id', $employee->login_id)->pluck('id')->all(),
                'calls_per_month' => 2]);
        }
        $launch = app(CompanyLaunchService::class);
        $launch->saveWorkflow('direct_dcr', 0);
        app(OnboardingApprovalService::class)->saveDraft(['required' => 'no']);
        expect($launch->readiness($employees->pluck('id')->all())['ready_count'])->toBe(13);
        $launch->launch($employees->take(13)->pluck('id')->all(), $launch->proposal($company->organization())->id);
        $rep = User::query()->where('email', 'mr1@imported.test')->sole();
        expect($launch->forUser($rep)['contact_kind'])->toBe('manager')
            ->and($launch->forUser($employees->last()->user)['launched'])->toBeFalse();
        VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
            'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
            'require_check_out_image' => false, 'require_general_visit_image' => false, 'enforce_minimum_duration' => false]);
        $login = $this->postJson('/api/v1/login', ['email' => $rep->email,
            'password' => 'Initial-Password42', 'device_name' => 'imported-company-test'])
            ->assertSuccessful()->assertJsonPath('user.company_launch.launched', true);
        auth()->forgetGuards();
        $this->withToken($login->json('token'));
        $this->getJson('/api/v1/me')->assertSuccessful()->assertJsonPath('data.id', $rep->id);
        $doctor = AccountMaster::query()->where('owner_id', $rep->id)->sole();
        $visit = $this->postJson('/api/v1/visits/direct', ['client_call_id' => (string) Str::uuid(),
            'company_id' => $doctor->id, 'date' => today()->toDateString()])->assertSuccessful()->json('data.id');
        $this->postJson('/api/v1/visits/'.$visit.'/check-in', [])->assertSuccessful();
        $this->postJson('/api/v1/visits/'.$visit.'/check-out', [])->assertSuccessful();
        $dcr = SalesDcr::query()->where('user_id', $rep->id)->sole();
        $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertSuccessful();
        expect($dcr->fresh()->approval_status)->toBe('approved');
    } finally {
        unlink($path);
    }
});
