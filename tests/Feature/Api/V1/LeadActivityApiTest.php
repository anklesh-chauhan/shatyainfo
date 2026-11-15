<?php

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
