<?php

use App\Models\FollowUp;
use App\Models\FollowUpMedia;
use App\Models\FollowUpPriority;
use App\Models\FollowUpResult;
use App\Models\FollowUpStatus;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function followUpApiGrant(User $user, array $names): void
{
    $user->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        $names,
    ));
}

function followUpApiLead(User $owner): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Follow-up lead',
        'status_id' => reportingLeadStatus('FU Lead '.fake()->unique()->numerify('###'))->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

function followUpApiRecord(Lead $lead, User $assignee, string $interaction, ?FollowUpStatus $status = null): FollowUp
{
    return FollowUp::query()->create([
        'followupable_type' => Lead::class,
        'followupable_id' => $lead->id,
        'user_id' => $assignee->id,
        'follow_up_date' => now(),
        'interaction' => $interaction,
        'follow_up_status_id' => $status?->id,
    ]);
}

it('forbids follow-ups without ViewAny:FollowUp', function () {
    Sanctum::actingAs(reportingUser('FollowUpApiDenied'));

    $this->getJson('/api/v1/follow-ups')->assertForbidden();
});

it('lists only visible follow-ups with lookup names', function () {
    $user = reportingUser('FollowUpApiViewer');
    $other = reportingUser('FollowUpApiOther');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp']);
    $status = FollowUpStatus::query()->create(['name' => 'Scheduled']);
    $lead = followUpApiLead($other);
    $mine = followUpApiRecord($lead, $user, 'Call about samples', $status);
    $hidden = followUpApiRecord($lead, $other, 'Other rep call');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.followupable_type', 'Lead')
        ->assertJsonPath('data.0.lead_id', $lead->id)
        ->assertJsonPath('data.0.lead_reference_code', $lead->fresh()->reference_code)
        ->assertJsonPath('data.0.status_name', 'Scheduled')
        ->assertJsonPath('data.0.user_id', $user->id)
        ->assertJsonPath('data.0.can_update', false)
        ->assertJsonMissing(['id' => $hidden->id])
        ->assertJsonMissingPath('data.0.lead_title');
});

it('shows a visible follow-up and hides unrelated ones', function () {
    $user = reportingUser('FollowUpApiShow');
    $other = reportingUser('FollowUpApiShowOther');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp', 'Update:FollowUp']);
    $lead = followUpApiLead($other);
    $mine = followUpApiRecord($lead, $user, 'Mine');
    $hidden = followUpApiRecord($lead, $other, 'Hidden');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups/'.$mine->id)
        ->assertSuccessful()
        ->assertJsonPath('data.id', $mine->id)
        ->assertJsonPath('data.interaction', 'Mine')
        ->assertJsonPath('data.can_update', true);

    $this->getJson('/api/v1/follow-ups/'.$hidden->id)->assertNotFound();
});

it('filters by a visible lead and rejects a hidden lead', function () {
    $user = reportingUser('FollowUpApiLeadFilter');
    $other = reportingUser('FollowUpApiLeadOther');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp', 'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead']);
    $ownLead = followUpApiLead($user);
    $otherLead = followUpApiLead($other);
    $onOwnLead = followUpApiRecord($ownLead, $user, 'On own lead');
    $onOtherLead = followUpApiRecord($otherLead, $user, 'On other lead');
    $othersOnOwnLead = followUpApiRecord($ownLead, $other, 'Someone else on own lead');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups?lead_id='.$ownLead->id)
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $onOwnLead->id)
        ->assertJsonMissing(['id' => $onOtherLead->id])
        ->assertJsonMissing(['id' => $othersOnOwnLead->id]);

    $this->getJson('/api/v1/follow-ups?lead_id='.$otherLead->id)->assertNotFound();
});

it('returns follow-up options with can_create', function () {
    $user = reportingUser('FollowUpApiOptions');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'Create:FollowUp']);
    FollowUpMedia::query()->create(['name' => 'Phone']);
    FollowUpResult::query()->create(['name' => 'Interested']);
    FollowUpStatus::query()->create(['name' => 'Pending']);
    FollowUpPriority::query()->create(['name' => 'High']);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups/options')
        ->assertSuccessful()
        ->assertJsonPath('data.can_create', true)
        ->assertJsonPath('data.media.0.name', 'Phone')
        ->assertJsonPath('data.results.0.name', 'Interested')
        ->assertJsonPath('data.statuses.0.name', 'Pending')
        ->assertJsonPath('data.priorities.0.name', 'High');

    Sanctum::actingAs(reportingUser('FollowUpApiOptionsDenied'));

    $this->getJson('/api/v1/follow-ups/options')->assertForbidden();
});

it('creates a follow-up on a visible lead assigned to the caller and logs lead activity', function () {
    $user = reportingUser('FollowUpApiCreate');
    $other = reportingUser('FollowUpApiCreateOther');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp', 'Create:FollowUp', 'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead']);
    $lead = followUpApiLead($user);
    $leadContact = reportingContact('Lead', 'Contact');
    $lead->forceFill(['contact_detail_id' => $leadContact->id])->save();
    $status = FollowUpStatus::query()->create(['name' => 'Pending']);
    $media = FollowUpMedia::query()->create(['name' => 'Visit']);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/follow-ups', [
        'lead_id' => $lead->id,
        'user_id' => $other->id,
        'follow_up_date' => '2026-11-20 10:30:00',
        'to_whom' => $leadContact->id,
        'interaction' => 'Discuss order',
        'follow_up_media_id' => $media->id,
        'follow_up_status_id' => $status->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.lead_id', $lead->id)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.to_whom', $leadContact->id)
        ->assertJsonPath('data.media_name', 'Visit')
        ->assertJsonPath('data.status_name', 'Pending');

    $followUp = FollowUp::query()->findOrFail($response->json('data.id'));

    expect($followUp->followupable_type)->toBe(Lead::class)
        ->and((int) $followUp->followupable_id)->toBe($lead->id)
        ->and((int) $followUp->user_id)->toBe($user->id)
        ->and((int) $followUp->created_by)->toBe($user->id);

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'user_id' => $user->id,
        'activity_type' => 'Follow-up Created',
    ]);
});

it('rejects follow-up create without permission, on a hidden lead, or for a hidden contact', function () {
    $user = reportingUser('FollowUpApiCreateGuard');
    $other = reportingUser('FollowUpApiCreateGuardOther');
    $status = FollowUpStatus::query()->create(['name' => 'Pending']);
    $ownLead = followUpApiLead($user);
    $hiddenLead = followUpApiLead($other);
    $hiddenContact = reportingContact('Hidden', 'Contact');
    $hiddenContact->forceFill(['created_by' => $other->id])->save();
    $payload = ['follow_up_date' => '2026-11-20 10:00:00', 'follow_up_status_id' => $status->id];

    Sanctum::actingAs($user);
    $this->postJson('/api/v1/follow-ups', [...$payload, 'lead_id' => $ownLead->id])->assertForbidden();

    followUpApiGrant($user, ['ViewAny:FollowUp', 'Create:FollowUp', 'ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead']);

    $this->postJson('/api/v1/follow-ups', [...$payload, 'lead_id' => $hiddenLead->id])->assertNotFound();

    $this->postJson('/api/v1/follow-ups', [...$payload, 'lead_id' => $ownLead->id, 'to_whom' => $hiddenContact->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to_whom']);

    $this->postJson('/api/v1/follow-ups', ['lead_id' => $ownLead->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['follow_up_date', 'follow_up_status_id']);

    expect(FollowUp::query()->count())->toBe(0);
});

it('updates only sent follow-up fields and clears explicit nulls', function () {
    $user = reportingUser('FollowUpApiUpdate');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp', 'Update:FollowUp']);
    $lead = followUpApiLead($user);
    $pending = FollowUpStatus::query()->create(['name' => 'Pending']);
    $done = FollowUpStatus::query()->create(['name' => 'Done']);
    $followUp = followUpApiRecord($lead, $user, 'Original interaction', $pending);
    $followUp->forceFill(['outcome' => 'Old outcome'])->save();

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/follow-ups/'.$followUp->id, [
        'follow_up_status_id' => $done->id,
        'outcome' => null,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.status_name', 'Done')
        ->assertJsonPath('data.interaction', 'Original interaction')
        ->assertJsonPath('data.outcome', null);

    $this->patchJson('/api/v1/follow-ups/'.$followUp->id, ['follow_up_status_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['follow_up_status_id']);

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'activity_type' => 'Follow-up Updated',
    ]);
});

it('rejects follow-up update for hidden records or without Update:FollowUp', function () {
    $user = reportingUser('FollowUpApiUpdateGuard');
    $other = reportingUser('FollowUpApiUpdateGuardOther');
    followUpApiGrant($user, ['ViewAny:FollowUp', 'View:FollowUp']);
    $lead = followUpApiLead($other);
    $mine = followUpApiRecord($lead, $user, 'Mine');
    $hidden = followUpApiRecord($lead, $other, 'Hidden');

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/follow-ups/'.$mine->id, ['interaction' => 'Changed'])->assertForbidden();

    followUpApiGrant($user, ['Update:FollowUp']);

    $this->patchJson('/api/v1/follow-ups/'.$hidden->id, ['interaction' => 'Changed'])->assertNotFound();

    expect($mine->fresh()->interaction)->toBe('Mine')
        ->and($hidden->fresh()->interaction)->toBe('Hidden');
});

it('searches visible follow-ups by interaction', function () {
    $user = reportingUser('FollowUpApiSearch');
    followUpApiGrant($user, ['ViewAny:FollowUp']);
    $lead = followUpApiLead($user);
    followUpApiRecord($lead, $user, 'Discuss pricing');
    $match = followUpApiRecord($lead, $user, 'Deliver brochure');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/follow-ups?search=brochure')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});
