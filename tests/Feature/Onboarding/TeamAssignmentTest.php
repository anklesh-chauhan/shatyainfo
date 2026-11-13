<?php

use App\Filament\Pages\Onboarding;
use App\Models\FieldUserHq;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Tenant;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeHierarchyImport;
use App\Services\Field\TeamAssignmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = reportingUser('Assignment Admin');
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->admin->givePermissionTo(Permission::findOrCreate('Update:AccountMaster', 'web'));
    $this->actingAs($this->admin);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    Organization::query()->create(['name' => 'Assignment Pharma', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->rep = reportingUser('Assignment MR');
    $this->other = reportingUser('Second MR');
    $this->hq = reportingLocation('Shared HQ');
    $this->territory = reportingTerritory('Shared Territory');
    $this->hq->territories()->attach($this->territory);
    $role = JobRole::query()->create(['name' => 'Assignment Representative', 'code' => 'ASSIGN-REP', 'level' => 1]);
    $this->position = Position::query()->create(['name' => 'Assignment Position', 'code' => 'ASSIGN-POS', 'job_role_id' => $role->id]);
    $this->position->territories()->attach($this->territory);
    $this->rep->employee->positions()->attach($this->position, ['is_primary' => true]);
    $this->other->employee->positions()->attach($this->position, ['is_primary' => true]);
    $this->service = app(TeamAssignmentService::class);
});

it('bulk assigns HQs with a read only preview for every working method', function (string $mode) {
    app(CompanyLaunchService::class)->saveWorkflow($mode, 0);
    $members = [$this->rep->employee->id, $this->other->employee->id];
    $preview = $this->service->previewHqs($members, $this->hq->id);
    expect($preview['errors'])->toBe([])->and(FieldUserHq::query()->count())->toBe(0);
    expect($this->service->assignHqs($members, $this->hq->id, false, $preview['fingerprint']))->toBe(2);
    expect(FieldUserHq::query()->where('location_master_id', $this->hq->id)->count())->toBe(2)
        ->and(FieldUserHq::query()->first()->updated_by)->toBe($this->admin->id);
})->with(['direct_dcr', 'flexible', 'formal_mtp']);

it('keeps existing HQs unless replacement is reviewed and rejects stale previews', function () {
    app(CompanyLaunchService::class)->saveWorkflow('flexible', 0);
    $old = reportingLocation('Existing HQ');
    $assignment = FieldUserHq::query()->create(['user_id' => $this->rep->id, 'location_master_id' => $old->id, 'updated_by' => $this->admin->id]);
    $members = [$this->rep->employee->id];
    $preview = $this->service->previewHqs($members, $this->hq->id);
    expect($this->service->assignHqs($members, $this->hq->id, false, $preview['fingerprint']))->toBe(0);
    $preview = $this->service->previewHqs($members, $this->hq->id, true);
    $assignment->update(['location_master_id' => reportingLocation('Changed HQ')->id]);
    expect(fn () => $this->service->assignHqs($members, $this->hq->id, true, $preview['fingerprint']))->toThrow(ValidationException::class);
    $preview = $this->service->previewHqs($members, $this->hq->id, true);
    expect($this->service->assignHqs($members, $this->hq->id, true, $preview['fingerprint']))->toBe(1);
});

it('rejects an entire monthly HQ batch when one MR has no matching territory', function () {
    app(CompanyLaunchService::class)->saveWorkflow('formal_mtp', 0);
    $this->other->employee->positions()->detach();
    $members = [$this->rep->employee->id, $this->other->employee->id];
    $preview = $this->service->previewHqs($members, $this->hq->id);
    expect($preview['errors'])->toHaveCount(1);
    expect(fn () => $this->service->assignHqs($members, $this->hq->id, false, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect(FieldUserHq::query()->count())->toBe(0);
});

it('maps customers for every working method without requiring HQ or changing customer details', function (string $mode) {
    app(CompanyLaunchService::class)->saveWorkflow($mode, 0);
    $doctor = reportingDoctor($this->admin, 'Mapped Doctor');
    $rows = [['line' => 2, 'customer_code' => $doctor->account_code, 'owner_email' => $this->rep->email]];
    $blocked = $this->service->previewMappings($rows);
    expect($blocked['errors'])->toHaveCount(1)->and($doctor->fresh()->owner_id)->toBe($this->admin->id);
    $preview = $this->service->previewMappings($rows, true);
    expect($preview['errors'])->toBe([])->and($preview['plans'][0]['action'])->toBe('Transfer');
    expect($this->service->importMappings($rows, true, $preview['fingerprint']))->toBe(1);
    expect($doctor->fresh()->owner_id)->toBe($this->rep->id)->and($doctor->fresh()->name)->toBe('Mapped Doctor')
        ->and(FieldUserHq::query()->count())->toBe(0);
    $rows[0]['owner_email'] = '';
    $preview = $this->service->previewMappings($rows);
    expect($this->service->importMappings($rows, false, $preview['fingerprint']))->toBe(0);
})->with(['direct_dcr', 'flexible', 'formal_mtp']);

it('rejects stale customer ownership and duplicate customer aliases without partial changes', function () {
    $doctor = reportingDoctor($this->admin, 'Mapped Doctor');
    $doctor->update(['external_reference' => 'EXT-DOCTOR']);
    $rows = [['line' => 2, 'customer_code' => $doctor->account_code, 'owner_email' => $this->rep->email]];
    $preview = $this->service->previewMappings($rows, true);
    $doctor->update(['owner_id' => $this->other->id]);
    expect(fn () => $this->service->importMappings($rows, true, $preview['fingerprint']))->toThrow(ValidationException::class);
    $rows[] = ['line' => 3, 'customer_code' => 'EXT-DOCTOR', 'owner_email' => $this->rep->email];
    $preview = $this->service->previewMappings($rows, true);
    expect($preview['errors'])->toHaveCount(1);
    expect(fn () => $this->service->importMappings($rows, true, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect($doctor->fresh()->owner_id)->toBe($this->other->id);
});

it('checks assignment permissions on every service entry point', function () {
    $this->actingAs($this->rep);
    expect(fn () => $this->service->previewHqs([$this->rep->employee->id], $this->hq->id))->toThrow(HttpException::class)
        ->and(fn () => $this->service->previewMappings([]))->toThrow(HttpException::class)
        ->and(fn () => $this->service->importMappings([], false, ''))->toThrow(HttpException::class);
});

it('imports HQ codes after employee positions and login resolution in every mode', function (string $mode) {
    app(CompanyLaunchService::class)->saveWorkflow($mode, 0);
    $row = ['employee_id' => $this->rep->employee->employee_id, 'hq_code' => $this->hq->location_code];
    $result = app(EmployeeHierarchyImport::class)->import([2 => $row]);
    expect($result->failed)->toBe([])
        ->and(FieldUserHq::query()->where('user_id', $this->rep->id)->sole()->location_master_id)->toBe($this->hq->id);
    $result = app(EmployeeHierarchyImport::class)->import([2 => [...$row, 'hq_code' => '']]);
    expect($result->failed)->toBe([])->and(FieldUserHq::query()->count())->toBe(1);
})->with(['direct_dcr', 'flexible', 'formal_mtp']);

it('rolls back employee changes for inactive or incompatible HQ codes', function (string $mode) {
    app(CompanyLaunchService::class)->saveWorkflow($mode, 0);
    if ($mode === 'formal_mtp') {
        $this->hq->territories()->detach();
    } else {
        $this->hq->update(['is_active' => false]);
    }
    $row = ['employee_id' => $this->rep->employee->employee_id, 'first_name' => 'Must roll back', 'hq_code' => $this->hq->location_code];
    $result = app(EmployeeHierarchyImport::class)->import([2 => $row]);
    expect($result->failed)->toHaveCount(1)->and(FieldUserHq::query()->count())->toBe(0)
        ->and($this->rep->employee->fresh()->first_name)->toBe('Assignment MR');
})->with(['direct_dcr', 'flexible', 'formal_mtp']);

it('downloads and reads the mapping workbook without importing reference sheets', function () {
    $doctor = reportingDoctor($this->admin, 'Workbook Doctor');
    $path = tempnam(sys_get_temp_dir(), 'mapping-test-');
    try {
        $this->service->writeMappingTemplate($path);
        $rows = $this->service->readMappings($path, true);
        expect($rows)->toHaveCount(1)->and($rows[0]['customer_code'])->toBe($doctor->account_code)
            ->and($rows[0]['owner_email'])->toBe('');
    } finally {
        unlink($path);
    }
});

it('renders and saves bulk assignments from the final review in all working methods', function (string $mode) {
    app(CompanyLaunchService::class)->saveWorkflow($mode, 0);
    $organization = Organization::query()->firstOrFail();
    $metadata = $organization->metadata;
    $metadata['company_setup']['approvals_applied'] = true;
    $metadata['company_setup']['territories_reviewed'] = true;
    $organization->update(['metadata' => $metadata]);
    $doctor = reportingDoctor($this->admin, 'Review Doctor');
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99997, 'name' => 'Review Test', 'master_setup_status' => 'ready']);
    $testingConnection = app('db')->connection();
    app('db')->extend('tenant', fn () => $testingConnection);
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    try {
        $file = UploadedFile::fake()->createWithContent('mapping.csv', "customer_code,owner_email\n{$doctor->account_code},{$this->rep->email}\n");
        Livewire::test(Onboarding::class)->assertSet('step', 6)
            ->assertSee('Assign HQs to selected MRs')->assertSee('Assign customers with one upload')
            ->set('launchMembers', [$this->rep->employee->id])->set('bulkHqId', $this->hq->id)
            ->call('previewBulkHqs')->assertHasNoErrors()->call('confirmBulkHqs')->assertHasNoErrors()
            ->set('allowCustomerTransfers', true)->set('mappingFile', $file)
            ->call('previewCustomerMappings')->assertHasNoErrors()->assertSee('Transfer')
            ->call('confirmCustomerMappings')->assertHasNoErrors();
        expect($doctor->fresh()->owner_id)->toBe($this->rep->id);
    } finally {
        Tenant::forgetCurrent();
    }
})->with(['direct_dcr', 'flexible', 'formal_mtp']);

it('rejects unknown and conflicting employee HQ codes without saving rows', function () {
    $base = ['employee_id' => $this->rep->employee->employee_id];
    $result = app(EmployeeHierarchyImport::class)->import([2 => [...$base, 'hq_code' => 'MISSING']]);
    expect($result->failed)->toHaveCount(1);
    $result = app(EmployeeHierarchyImport::class)->import([
        2 => [...$base, 'hq_code' => $this->hq->location_code],
        3 => [...$base, 'hq_code' => 'DIFFERENT'],
    ]);
    expect($result->failed)->toHaveCount(1)->and($result->failed[0]['message'])->toContain('Conflicting hq_code')
        ->and(FieldUserHq::query()->count())->toBe(0);
});

it('rejects inactive customer recipients and missing accounts atomically', function () {
    $doctor = reportingDoctor($this->admin, 'Doctor');
    $rows = [
        ['line' => 2, 'customer_code' => $doctor->account_code, 'owner_email' => $this->rep->email],
        ['line' => 3, 'customer_code' => 'MISSING', 'owner_email' => $this->other->email],
    ];
    $preview = $this->service->previewMappings($rows, true);
    expect(fn () => $this->service->importMappings($rows, true, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect($doctor->fresh()->owner_id)->toBe($this->admin->id);
    $this->rep->employee->forceFill(['is_active' => false])->saveQuietly();
    expect($this->service->previewMappings([$rows[0]], true)['errors'])->toHaveCount(1);
});

it('rejects customer mapping without account update permission', function () {
    $doctor = reportingDoctor($this->rep, 'Restricted Doctor');
    $this->rep->givePermissionTo(Permission::findOrCreate('Manage:FieldTeam', 'web'));
    $this->rep->givePermissionTo(Permission::findOrCreate('ViewOwn:AccountMaster', 'web'));
    $this->actingAs($this->rep);
    $preview = $this->service->previewMappings([['line' => 2, 'customer_code' => $doctor->account_code, 'owner_email' => $this->other->email]], true);
    expect($preview['errors'])->toHaveCount(1)->and($preview['plans'])->toBe([]);
});
