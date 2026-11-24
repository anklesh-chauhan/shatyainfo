<?php

use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\FollowUpStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function leadActivityApiGrant(User $user, array $names): void
{
    $user->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        $names,
    ));
}

function leadActivityApiLead(User $owner): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Activity lead',
        'status_id' => reportingLeadStatus('LA Lead '.fake()->unique()->numerify('###'))->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function leadActivityApiRecord(Lead $lead, User $user, string $type, string $createdAt): LeadActivity
{
    $activity = LeadActivity::query()->create([
        'lead_id' => $lead->id,
        'user_id' => $user->id,
        'activity_type' => $type,
        'description' => $type.' description',
    ]);
    $activity->forceFill(['created_at' => $createdAt])->save();

    return $activity;
}

it('lists all activities of a visible lead newest first', function () {
    $user = reportingUser('LeadActivityApiViewer');
    $other = reportingUser('LeadActivityApiOther');
    leadActivityApiGrant($user, ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead', 'ViewAny:LeadActivity']);
    $lead = leadActivityApiLead($user);
    $otherLead = leadActivityApiLead($other);
    $older = leadActivityApiRecord($lead, $user, 'Note Added', '2026-11-01 09:00:00');
    $newer = leadActivityApiRecord($lead, $other, 'Item Attached', '2026-11-02 09:00:00');
    $elsewhere = leadActivityApiRecord($otherLead, $user, 'Elsewhere', '2026-11-03 09:00:00');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/activities')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.activity_type', 'Item Attached')
        ->assertJsonPath('data.0.user_name', $other->name)
        ->assertJsonPath('data.0.created_at', '2026-11-02 09:00:00')
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonMissing(['id' => $elsewhere->id]);
});

it('validates standalone activity selectors and preserves unchanged relationships', function () {
    $actor = reportingUser('Activity Mutation Actor');
    $other = reportingUser('Activity Mutation Other');
    leadActivityApiGrant($actor, [
        'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead',
        'ViewAny:LeadActivity', 'View:LeadActivity', 'Create:LeadActivity', 'Update:LeadActivity', 'Delete:LeadActivity',
    ]);
    $lead = leadActivityApiLead($actor);
    $secondLead = leadActivityApiLead($actor);
    $hiddenLead = leadActivityApiLead($other);
    $hiddenParentActivity = leadActivityApiRecord($hiddenLead, $actor, 'Visible activity on hidden lead', '2026-11-01 09:00:00');
    $legacy = leadActivityApiRecord($lead, $other, 'Legacy actor', '2026-11-01 09:00:00');
    $legacy->forceFill(['created_by' => $actor->id])->save();
    $path = '/api/v1/lead-activities';
    $input = ['activity_type' => 'Customer called', 'description' => 'Requested a quote'];
    Sanctum::actingAs($actor);

    $this->getJson($path.'/users?search=Mutation')->assertOk()
        ->assertExactJson(['data' => [['id' => $actor->id, 'label' => $actor->name]], 'meta' => ['last_page' => 1]]);
    $this->postJson($path, $input)->assertUnprocessable()->assertJsonValidationErrors(['lead_id', 'user_id']);
    $this->postJson($path, $input + ['lead_id' => $hiddenLead->id, 'user_id' => $actor->id])->assertNotFound();
    $this->postJson($path, $input + ['lead_id' => $lead->id, 'user_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');

    $id = $this->postJson($path, $input + ['lead_id' => $lead->id, 'user_id' => $actor->id])->assertCreated()
        ->assertJsonPath('data.can_update', true)->assertJsonPath('data.can_delete', true)->json('data.id');
    $this->getJson($path.'?search=hidden%20lead')->assertOk()->assertJsonPath('can_create', true)
        ->assertJsonPath('data.0.can_update', false)->assertJsonPath('data.0.can_delete', false);
    $this->patchJson($path.'/'.$hiddenParentActivity->id, $input)->assertNotFound();
    $this->deleteJson($path.'/'.$hiddenParentActivity->id)->assertNotFound();
    $this->patchJson($path.'/'.$id, $input + ['lead_id' => $hiddenLead->id])->assertNotFound();
    $this->patchJson($path.'/'.$id, $input + ['lead_id' => $secondLead->id])->assertOk()
        ->assertJsonPath('data.lead_id', $secondLead->id)->assertJsonPath('data.user_id', $actor->id);
    $this->patchJson($path.'/'.$legacy->id, $input)->assertOk()->assertJsonPath('data.user_id', $other->id);
    $this->deleteJson($path.'/'.$id)->assertOk()->assertJsonPath('data.deleted', 1);
    $this->assertSoftDeleted('lead_activities', ['id' => $id, 'deleted_by' => $actor->id]);

    $actor->revokePermissionTo('Create:LeadActivity');
    $this->getJson($path)->assertOk()->assertJsonPath('can_create', false);
    $this->postJson($path, $input + ['lead_id' => $lead->id, 'user_id' => $actor->id])->assertForbidden();
});

it('keeps lead relation activity mutations scoped and bulk delete atomic', function () {
    $actor = reportingUser('Activity Timeline Actor');
    $other = reportingUser('Activity Timeline Other');
    leadActivityApiGrant($actor, [
        'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead',
        'ViewAny:LeadActivity', 'View:LeadActivity', 'Create:LeadActivity', 'Update:LeadActivity', 'Delete:LeadActivity',
    ]);
    $lead = leadActivityApiLead($actor);
    $otherLead = leadActivityApiLead($actor);
    $elsewhere = leadActivityApiRecord($otherLead, $actor, 'Other lead', '2026-11-01 09:00:00');
    $approved = leadActivityApiRecord($lead, $other, 'Approved activity', '2026-11-02 09:00:00');
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => false]);
    $approval = $approved->approval()->create([
        'approval_flow_id' => $flow->id, 'requested_by' => $other->id, 'module' => 'LeadActivity',
        'record_type' => LeadActivity::class, 'record_id' => $approved->id, 'approval_status' => 'approved',
    ]);
    expect(ApprovalSetting::supportsModule('LeadActivity'))->toBeFalse();
    $path = '/api/v1/leads/'.$lead->id.'/activities';
    $input = ['activity_type' => 'Visit', 'description' => 'Discussed the quote'];
    Sanctum::actingAs($actor);

    $this->getJson($path)->assertOk()->assertJsonPath('can_create', true)
        ->assertJsonPath('data.0.can_update', false)->assertJsonPath('data.0.can_delete', false);
    $this->postJson($path, $input + ['user_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $id = $this->postJson($path, $input)->assertCreated()->assertJsonPath('data.user_id', $actor->id)->json('data.id');
    $this->getJson($path)->assertOk()->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.can_update', true)->assertJsonPath('data.0.can_delete', true);
    $this->patchJson($path.'/'.$id, $input + ['lead_id' => $otherLead->id])->assertUnprocessable()->assertJsonValidationErrors('lead_id');
    $this->patchJson($path.'/'.$id, ['activity_type' => 'Revised', 'description' => 'Revised description'])->assertOk()
        ->assertJsonPath('data.activity_type', 'Revised')->assertJsonPath('data.lead_id', $lead->id);
    $this->patchJson($path.'/'.$approved->id, $input)->assertUnprocessable();
    $this->postJson($path.'/bulk-delete', ['ids' => [$id, $id]])->assertUnprocessable();
    $this->postJson($path.'/bulk-delete', ['ids' => [$id, $elsewhere->id]])->assertNotFound();
    $this->postJson($path.'/bulk-delete', ['ids' => [$id, $approved->id]])->assertUnprocessable();
    $this->assertNotSoftDeleted('lead_activities', ['id' => $id]);
    $this->assertNotSoftDeleted($approved);

    leadActivityApiGrant($actor, ['OverrideApproval:LeadActivity']);
    $this->patchJson($path.'/'.$approved->id, $input)->assertOk()->assertJsonPath('data.user_id', $other->id);
    $this->postJson($path.'/bulk-delete', ['ids' => [$id, $approved->id]])->assertOk()->assertJsonPath('data.deleted', 2);
    $this->assertSoftDeleted('lead_activities', ['id' => $id, 'deleted_by' => $actor->id]);
    $this->assertSoftDeleted($approved);
    $this->assertNotSoftDeleted($elsewhere);
    $this->assertDatabaseHas('approvals', ['id' => $approval->id, 'approval_status' => 'approved']);
    $this->assertDatabaseHas('approval_overrides', [
        'model_type' => LeadActivity::class, 'model_id' => $approved->id, 'user_id' => $actor->id,
        'reason' => 'Lead activity updated through the mobile API',
    ]);
    $this->assertDatabaseHas('approval_overrides', [
        'model_type' => LeadActivity::class, 'model_id' => $approved->id, 'user_id' => $actor->id,
        'reason' => 'Lead activity deleted through the mobile API',
    ]);
});

it('forbids lead activities without ViewAny:LeadActivity', function () {
    $user = reportingUser('LeadActivityApiDenied');
    leadActivityApiGrant($user, ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead']);
    $lead = leadActivityApiLead($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/activities')->assertForbidden();
});

it('returns 404 for activities of a hidden lead', function () {
    $user = reportingUser('LeadActivityApiHidden');
    $other = reportingUser('LeadActivityApiHiddenOther');
    leadActivityApiGrant($user, ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead', 'ViewAny:LeadActivity']);
    $hiddenLead = leadActivityApiLead($other);
    leadActivityApiRecord($hiddenLead, $other, 'Secret', '2026-11-01 09:00:00');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$hiddenLead->id.'/activities')->assertNotFound();
});

it('includes the activity written by the follow-up create API', function () {
    $user = reportingUser('LeadActivityApiFollowUp');
    leadActivityApiGrant($user, [
        'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead', 'ViewAny:LeadActivity',
        'ViewAny:FollowUp', 'View:FollowUp', 'Create:FollowUp',
    ]);
    $lead = leadActivityApiLead($user);
    $status = FollowUpStatus::query()->create(['name' => 'Pending']);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/follow-ups', [
        'lead_id' => $lead->id,
        'follow_up_date' => '2026-11-20 10:30:00',
        'follow_up_status_id' => $status->id,
    ])->assertSuccessful();

    $this->getJson('/api/v1/leads/'.$lead->id.'/activities')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.activity_type', 'Follow-up Created')
        ->assertJsonPath('data.0.user_id', $user->id);
});

it('lists scoped activity across leads and filters dates and visible references', function () {
    $user = reportingUser('Activity Register Reader');
    $other = reportingUser('Activity Register Other');
    leadActivityApiGrant($user, ['ViewAny:LeadActivity', 'View:LeadActivity', 'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead']);
    $lead = leadActivityApiLead($user);
    $account = reportingDoctor($user, 'Activity Register Clinic');
    $lead->forceFill(['reference_code' => 'REGISTER-VISIBLE', 'account_master_id' => $account->id])->save();
    $hiddenLead = leadActivityApiLead($other);
    $hiddenLead->forceFill(['reference_code' => 'REGISTER-HIDDEN'])->save();
    $old = leadActivityApiRecord($lead, $user, 'Recorded call', '2026-11-01 23:59:59');
    $latest = leadActivityApiRecord($hiddenLead, $user, 'Follow-up added', '2026-11-02 00:00:00');
    leadActivityApiRecord($lead, $other, 'Unrelated activity', '2026-11-03 09:00:00');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/lead-activities?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.0.lead_id', null)
        ->assertJsonPath('data.0.lead_reference_code', null)
        ->assertJsonPath('data.0.lead_name', null);
    $this->getJson('/api/v1/lead-activities?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.id', $old->id)
        ->assertJsonPath('data.0.lead_id', $lead->id)
        ->assertJsonPath('data.0.lead_reference_code', 'REGISTER-VISIBLE')
        ->assertJsonPath('data.0.lead_name', 'Activity Register Clinic');
    $this->getJson('/api/v1/lead-activities?search=REGISTER-HIDDEN')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/lead-activities?search=REGISTER-VISIBLE')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/v1/lead-activities?search=Register%20Clinic')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/v1/lead-activities?from=2026-11-01&to=2026-11-01&search=recorded')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/v1/lead-activities?to=2026-11-01')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/lead-activities?from=2026-11-02&to=2026-11-01')
        ->assertUnprocessable()->assertJsonValidationErrors('to');

    $user->revokePermissionTo('View:Lead');
    $this->getJson('/api/v1/lead-activities?search=recorded')->assertOk()
        ->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.0.lead_id', null);
});
