<?php

use App\Filament\Resources\SalesDcrs\Pages\CreateSalesDcr;
use App\Filament\Resources\SalesDcrs\Pages\ListSalesDcrs;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\SalesDcr;
use App\Models\Visit;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('reviews only visible DCRs and offers the assigned reviewer a scoped report link', function () {
    $manager = grantPagePermissions(fieldReportingUser('DCR review manager'),
        'ViewOwnTerritory:SalesDcr', 'ViewOwnTerritory:Visit', 'View:MyApprovals');
    $owner = fieldReportingUser('DCR review owner');
    $unrelated = grantPagePermissions(fieldReportingUser('DCR outside rep'), 'View:MyApprovals');
    $territory = reportingTerritory('Review territory');
    $patch = reportingPatch($territory, 'Review patch');
    $role = JobRole::create(['name' => 'DCR reviewer', 'code' => 'DCRREVIEW', 'level' => 1]);
    $position = Position::create(['name' => 'DCR reviewer', 'code' => 'DCRREVIEWPOS', 'job_role_id' => $role->id]);
    $position->territories()->attach($territory);
    $manager->employee->positions()->attach($position, ['is_primary' => true]);
    $make = fn ($user, array $data = []): SalesDcr => SalesDcr::forceCreate([
        'user_id' => $user->id, 'dcr_date' => today(), 'created_by' => $user->id,
        'approval_status' => 'draft', ...$data,
    ]);
    $mine = $make($manager);
    $outside = $make($unrelated);
    $report = $make($owner, ['territory_id' => $territory->id, 'approval_status' => 'submitted', 'remarks' => 'Reviewed availability']);
    Visit::withoutEvents(fn (): Visit => Visit::forceCreate([
        'document_number' => 'DCR-REVIEW-VISIT', 'employee_id' => $owner->id,
        'sales_dcr_id' => $report->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(), 'visit_status' => 'completed',
    ]));
    $flow = ApprovalFlow::create(['module' => 'SalesDcr', 'territory_id' => $territory->id]);
    $approval = Approval::create([
        'approvable_type' => SalesDcr::class, 'approvable_id' => $report->id,
        'approval_flow_id' => $flow->id, 'requested_by' => $owner->id, 'approval_status' => 'pending',
    ]);
    $step = $approval->steps()->create([
        'step_order' => 1, 'job_role_id' => $role->id, 'assigned_user_id' => $manager->id, 'status' => 'pending',
    ]);

    Sanctum::actingAs($manager->fresh());
    $base = '/api/v1/dcrs';
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.can_open_workspace', true)
        ->assertJsonPath('meta.can_view_team', true);
    $this->getJson($base.'?scope=team&approval_status=submitted&user_id='.$owner->id.'&from='.today()->toDateString())
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $report->id)
        ->assertJsonPath('data.0.visits_count', 1)->assertJsonPath('data.0.can_open_workspace', false)
        ->assertJsonCount(1, 'meta.users')->assertJsonPath('meta.users.0.id', $owner->id);
    $this->getJson($base.'?from=2026-11-20&to=2026-11-19')->assertUnprocessable()->assertJsonValidationErrors('to');
    $detail = $base.'/'.$report->id.'/register?scope=team';
    $this->getJson($detail)->assertOk()->assertJsonPath('data.remarks', 'Reviewed availability')
        ->assertJsonPath('data.visits.0.document_number', 'DCR-REVIEW-VISIT')->assertJsonMissingPath('data.capabilities');
    $this->getJson($base.'/'.$outside->id.'/register?scope=team')->assertNotFound();
    $this->getJson($base.'/'.$report->id)->assertNotFound();
    $this->postJson($base.'/'.$report->id.'/submit')->assertNotFound();
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertOk()->assertJsonPath('data.dcr_scope', 'team');

    $manager->revokePermissionTo('View:Visit');
    Sanctum::actingAs($manager->fresh());
    $this->getJson($detail)->assertOk()->assertJsonMissingPath('data.visits');
    $manager->revokePermissionTo('ViewOwnTerritory:SalesDcr');
    Sanctum::actingAs($manager->fresh());
    $this->getJson($detail)->assertNotFound();
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertOk()->assertJsonMissingPath('data.dcr_scope');
    Sanctum::actingAs($unrelated);
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertNotFound();
});

it('protects DCR evidence and validates the full selection and restore collision before writing', function () {
    $user = fieldReportingUser('DCR lifecycle');
    $other = reportingUser('Unrelated DCR lifecycle');
    $draft = SalesDcr::forceCreate(['user_id' => $user->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $history = SalesDcr::forceCreate(['user_id' => $user->id, 'dcr_date' => today(), 'approval_status' => 'draft', 'remarks' => 'Retain field notes']);
    $unrelated = SalesDcr::forceCreate(['user_id' => $other->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    Sanctum::actingAs($user);
    $base = '/api/v1/dcrs';
    $run = fn (string $action, array $ids) => $this->postJson($base.'/register-actions', [
        'scope' => 'personal', 'action' => $action, 'ids' => $ids,
    ]);
    $run('delete', [$draft->id])->assertForbidden();

    grantPagePermissions($user, 'Delete:SalesDcr', 'Restore:SalesDcr', 'ForceDelete:SalesDcr');
    $user->revokePermissionTo('Update:SalesDcr');
    Sanctum::actingAs($user->fresh());
    $run('delete', [$draft->id, $history->id])->assertUnprocessable();
    $run('delete', [$draft->id, $unrelated->id])->assertNotFound();
    $this->assertNotSoftDeleted('sales_dcrs', ['id' => $draft->id]);
    $run('delete', [$draft->id])->assertOk()->assertJsonPath('data.updated', 1);
    $this->getJson($base.'?trash=only')->assertOk()->assertJsonPath('data.0.id', $draft->id)
        ->assertJsonPath('data.0.can_open_workspace', false);
    $run('restore', [$draft->id])->assertConflict();
    $run('forceDelete', [$draft->id])->assertOk();
    $this->assertDatabaseMissing('sales_dcrs', ['id' => $draft->id]);
    $this->assertNotSoftDeleted('sales_dcrs', ['id' => $history->id]);

    grantPagePermissions($other, 'ViewAny:SalesDcr', 'View:SalesDcr', 'Delete:SalesDcr', 'Restore:SalesDcr');
    Sanctum::actingAs($other->fresh());
    $run('delete', [$unrelated->id])->assertOk();
    $run('restore', [$unrelated->id])->assertOk();
    $this->assertNotSoftDeleted('sales_dcrs', ['id' => $unrelated->id]);
});

it('returns one owned DCR for the requested day', function () {
    $user = fieldReportingUser('DcrApiRep');
    Sanctum::actingAs($user);
    $first = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.user_id', $user->id);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $first->json('data.id'));
    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not expose another representatives DCR', function () {
    $owner = fieldReportingUser('DcrOwner');
    Sanctum::actingAs($owner);
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    Sanctum::actingAs(fieldReportingUser('DcrOther'));
    $this->getJson('/api/v1/dcrs/'.$id)->assertNotFound();
});

it('hides another users DCR in the Admin list and the field API', function () {
    $owner = fieldReportingUser('DcrListOwner');
    $viewer = fieldReportingUser('DcrListViewer');
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:SalesDcr', 'web'));
    $dcr = SalesDcr::query()->create([
        'user_id' => $owner->id,
        'dcr_date' => today()->toDateString(),
        'approval_status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    expect($viewer->can('AccessAllRecords'))->toBeFalse()
        ->and($viewer->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse();

    $this->actingAs($viewer, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListSalesDcrs::class)->assertCanNotSeeTableRecords([$dcr]);

    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dcrs/'.$dcr->id)->assertNotFound();
});

it('rejects submission of an empty DCR', function () {
    Sanctum::actingAs(fieldReportingUser('EmptyDcr'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('dcr');
});

it('denies reporting to accounts without permissions and does not create rows for read-only accounts', function () {
    $viewer = reportingUser('Roleless DCR viewer');
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/dcrs/today')->assertForbidden();

    grantPagePermissions($viewer, 'ViewAny:SalesDcr', 'View:SalesDcr');
    Sanctum::actingAs($viewer->fresh());
    $this->getJson('/api/v1/dcrs/today')->assertForbidden();
    expect(SalesDcr::query()->where('user_id', $viewer->id)->exists())->toBeFalse();

    $report = SalesDcr::forceCreate(['user_id' => $viewer->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.capabilities.submit', false);
    $this->postJson('/api/v1/dcrs/'.$report->id.'/submit')->assertForbidden();
});

it('rejects assigning an Admin report to a user outside the field team', function () {
    $actor = fieldReportingUser('Report Form Actor');
    $unrelated = reportingUser('Unrelated Report Owner');
    $this->actingAs($actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateSalesDcr::class)
        ->assertSuccessful()
        ->fillForm(['dcr_date' => today()->toDateString(), 'user_id' => $unrelated->id])
        ->call('create')
        ->assertHasFormErrors(['user_id']);

    expect(SalesDcr::query()->where('user_id', $unrelated->id)->exists())->toBeFalse();
});
