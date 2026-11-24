<?php

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\ApprovalFlow;
use App\Models\ContactDetail;
use App\Models\JobRole;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Position;
use App\Models\Territory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function leadApiPermissions(array $extra = []): array
{
    return array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        [
            'ViewAny:Lead',
            'View:Lead',
            'ViewOwn:Lead',
            ...$extra,
        ],
    );
}

function leadAccountPermissions(): array
{
    return [
        Permission::findOrCreate('ViewAny:AccountMaster', 'web'),
        Permission::findOrCreate('View:AccountMaster', 'web'),
        Permission::findOrCreate('ViewOwn:AccountMaster', 'web'),
    ];
}

function reportingLeadSource(string $name): LeadSource
{
    return LeadSource::query()->firstOrCreate(
        ['name' => $name.' '.fake()->unique()->numerify('###')],
    );
}

function leadAssignTerritory(User $user, Territory $territory): void
{
    $code = fake()->unique()->bothify('LEADPOS###');
    $role = JobRole::query()->create(['name' => 'Lead Rep '.$code, 'code' => $code, 'level' => 1]);
    $position = Position::query()->create(['name' => 'Lead Rep '.$code, 'code' => $code, 'job_role_id' => $role->id]);
    $position->territories()->attach($territory->id);
    $user->employee->positions()->attach($position->id, ['is_primary' => true]);
}

it('lists and shows owned leads and hides unrelated ones', function () {
    $owner = reportingUser('LeadApiOwner');
    $other = reportingUser('LeadApiOther');
    $owner->givePermissionTo(leadApiPermissions());
    $status = reportingLeadStatus('Open');

    $owned = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Owned clinic lead',
        'annual_revenue' => 1500,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $hidden = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $other->id,
        'description' => 'Hidden lead',
        'annual_revenue' => 900,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/leads')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $owned->id)
        ->assertJsonMissing(['id' => $hidden->id]);

    $this->getJson('/api/v1/leads/'.$owned->id)
        ->assertSuccessful()
        ->assertJsonPath('data.id', $owned->id)
        ->assertJsonPath('data.owner_id', $owner->id)
        ->assertJsonPath('data.status_id', $status->id)
        ->assertJsonPath('data.description', 'Owned clinic lead');

    $this->getJson('/api/v1/leads/'.$hidden->id)
        ->assertNotFound();
});

it('rejects lead list without ViewAny', function () {
    Sanctum::actingAs(reportingUser('LeadApiDenied'));

    $this->getJson('/api/v1/leads')->assertForbidden();
});

it('searches leads by description within visibility', function () {
    $owner = reportingUser('LeadApiSearch');
    $owner->givePermissionTo(leadApiPermissions());
    $status = reportingLeadStatus('Search');

    Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Alpha clinic opportunity',
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $beta = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Beta hospital opportunity',
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/leads?search=Beta')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $beta->id)
        ->assertJsonMissing(['description' => 'Alpha clinic opportunity']);
});

it('creates an owned lead for the authenticated user', function () {
    $user = reportingUser('LeadApiCreate');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $status = reportingLeadStatus('New');
    $source = reportingLeadSource('Referral');
    $account = reportingDoctor($user, 'Dr Lead Account');
    $user->givePermissionTo(leadAccountPermissions());

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'annual_revenue' => 2500,
        'account_master_id' => $account->id,
        'description' => 'Opened from mobile',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.owner_id', $user->id)
        ->assertJsonPath('data.account_master_id', $account->id)
        ->assertJsonPath('data.status_id', $status->id)
        ->assertJsonPath('data.lead_source_id', $source->id)
        ->assertJsonPath('data.annual_revenue', 2500)
        ->assertJsonPath('data.description', 'Opened from mobile');

    $lead = Lead::query()->where('description', 'Opened from mobile')->sole();
    expect($lead->owner_id)->toBe($user->id)
        ->and($lead->status_type)->toBe(LeadStatus::class)
        ->and($lead->reference_code)->not->toBeEmpty();
});

it('forbids lead create without Create permission', function () {
    $user = reportingUser('LeadApiCreateDenied');
    $user->givePermissionTo(leadApiPermissions());
    $status = reportingLeadStatus('Denied Status');
    $source = reportingLeadSource('Denied Source');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'description' => 'Forbidden Lead',
    ])->assertForbidden();
});

it('updates a visible owned lead', function () {
    $user = reportingUser('LeadApiUpdate');
    $user->givePermissionTo(leadApiPermissions(['Update:Lead']));
    $status = reportingLeadStatus('Open');
    $nextStatus = reportingLeadStatus('Qualified');
    $source = reportingLeadSource('Web');

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'description' => 'Before Update',
        'annual_revenue' => 500,
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/leads/'.$lead->id, [
        'description' => 'After Update',
        'annual_revenue' => 750,
        'status_id' => $nextStatus->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.description', 'After Update')
        ->assertJsonPath('data.annual_revenue', 750)
        ->assertJsonPath('data.status_id', $nextStatus->id);

    expect($lead->fresh()->owner_id)->toBe($user->id)
        ->and($lead->fresh()->status_type)->toBe(LeadStatus::class);
});

it('does not update an unrelated lead', function () {
    $owner = reportingUser('LeadApiUpdateOwner');
    $other = reportingUser('LeadApiUpdateOther');
    $other->givePermissionTo(leadApiPermissions(['Update:Lead']));
    $status = reportingLeadStatus('Hidden Update');
    $source = reportingLeadSource('Hidden Source');

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Someone Else Lead',
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Sanctum::actingAs($other);

    $this->patchJson('/api/v1/leads/'.$lead->id, [
        'description' => 'Hijacked',
    ])->assertNotFound();
});

it('returns authorized lead form options and edit capabilities', function () {
    $user = reportingUser('LeadApiOptions');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead', 'Update:Lead']));
    $status = reportingLeadStatus('Option Status');
    $source = reportingLeadSource('Option Source');
    $account = reportingDoctor($user, 'Dr Lead Options');
    $contact = reportingContact('Option', 'Contact');
    $account->contactDetails()->attach($contact->id);
    $address = Address::query()->create([
        'street' => '12 Option Street',
        'pin_code' => '400001',
        'addressable_id' => $account->id,
        'addressable_type' => AccountMaster::class,
        'is_primary' => true,
    ]);
    $territory = reportingTerritory('Lead Options West');

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'account_master_id' => $account->id,
        'contact_detail_id' => $contact->id,
        'address_id' => $address->id,
        'territory_id' => $territory->id,
        'annual_revenue' => 400,
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/options')
        ->assertSuccessful()
        ->assertJsonPath('data.can_create', true)
        ->assertJsonFragment(['id' => $status->id, 'name' => $status->name])
        ->assertJsonFragment(['id' => $source->id, 'name' => $source->name]);

    $this->getJson('/api/v1/leads/'.$lead->id)
        ->assertSuccessful()
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.status_id', $status->id)
        ->assertJsonPath('data.lead_source_id', $source->id)
        ->assertJsonPath('data.account_master_id', $account->id)
        ->assertJsonPath('data.contact_detail_id', $contact->id)
        ->assertJsonPath('data.address_id', $address->id)
        ->assertJsonPath('data.address_street', '12 Option Street')
        ->assertJsonPath('data.territory_id', $territory->id);
});

it('marks can_create and can_update false without write permissions', function () {
    $user = reportingUser('LeadApiCapsDenied');
    $user->givePermissionTo(leadApiPermissions());
    $status = reportingLeadStatus('Read Status');
    $source = reportingLeadSource('Read Source');

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'description' => 'Read Only Lead',
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/options')
        ->assertSuccessful()
        ->assertJsonPath('data.can_create', false);

    $this->getJson('/api/v1/leads/'.$lead->id)
        ->assertSuccessful()
        ->assertJsonPath('data.can_update', false);
});

it('rejects create with an invisible account', function () {
    $owner = reportingUser('LeadApiAcctOwner');
    $user = reportingUser('LeadApiAcctDenied');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $user->givePermissionTo(leadAccountPermissions());
    $status = reportingLeadStatus('Hidden Acct Status');
    $source = reportingLeadSource('Hidden Acct Source');
    $hiddenAccount = reportingDoctor($owner, 'Dr Hidden Lead Account');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'account_master_id' => $hiddenAccount->id,
        'description' => 'Invisible Account Lead',
    ])->assertNotFound();
});

it('rejects create when contact or address relations are invalid', function () {
    $user = reportingUser('LeadApiRelation');
    $other = reportingUser('LeadApiRelationOther');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $user->givePermissionTo(leadAccountPermissions());
    $status = reportingLeadStatus('Relation Status');
    $source = reportingLeadSource('Relation Source');
    $account = reportingDoctor($user, 'Dr Lead Relation Account');
    $unlinkedContact = reportingContact('Unlinked', 'Buyer');
    $foreignAccount = reportingDoctor($other, 'Dr Lead Foreign');
    $foreignAddress = Address::query()->create([
        'street' => '99 Foreign Lane',
        'pin_code' => '400002',
        'addressable_id' => $foreignAccount->id,
        'addressable_type' => AccountMaster::class,
        'is_primary' => true,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'account_master_id' => $account->id,
        'contact_detail_id' => $unlinkedContact->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id']);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'account_master_id' => $account->id,
        'address_id' => $foreignAddress->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address_id']);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'address_id' => $foreignAddress->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address_id']);
});

it('creates a lead with valid account contact and address relations', function () {
    $user = reportingUser('LeadApiValidRel');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $user->givePermissionTo(leadAccountPermissions());
    $status = reportingLeadStatus('Valid Rel Status');
    $source = reportingLeadSource('Valid Rel Source');
    $account = reportingDoctor($user, 'Dr Lead Valid Rel');
    $contact = reportingContact('Valid', 'Rel');
    $account->contactDetails()->attach($contact->id);
    $address = Address::query()->create([
        'street' => '1 Valid Road',
        'pin_code' => '400003',
        'addressable_id' => $contact->id,
        'addressable_type' => ContactDetail::class,
        'contact_detail_id' => $contact->id,
        'is_primary' => true,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'annual_revenue' => 880,
        'account_master_id' => $account->id,
        'contact_detail_id' => $contact->id,
        'address_id' => $address->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.account_master_id', $account->id)
        ->assertJsonPath('data.contact_detail_id', $contact->id)
        ->assertJsonPath('data.address_id', $address->id)
        ->assertJsonPath('data.can_update', false);
});

it('lists only assigned territories in lead options', function () {
    $user = reportingUser('LeadApiTerritoryOptions');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $assigned = reportingTerritory('Lead Assigned North');
    $unassigned = reportingTerritory('Lead Unassigned South');
    leadAssignTerritory($user, $assigned);

    Sanctum::actingAs($user->fresh());

    $this->getJson('/api/v1/leads/options')
        ->assertSuccessful()
        ->assertJsonPath('data.territories.0.id', $assigned->id)
        ->assertJsonCount(1, 'data.territories')
        ->assertJsonMissing(['id' => $unassigned->id, 'name' => $unassigned->name]);
});

it('creates a lead only in an assigned territory', function () {
    $user = reportingUser('LeadApiTerritoryCreate');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $status = reportingLeadStatus('Territory Create Status');
    $source = reportingLeadSource('Territory Create Source');
    $assigned = reportingTerritory('Lead Create Assigned');
    $unassigned = reportingTerritory('Lead Create Unassigned');
    leadAssignTerritory($user, $assigned);

    Sanctum::actingAs($user->fresh());

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'territory_id' => $unassigned->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['territory_id']);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'territory_id' => $assigned->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.territory_id', $assigned->id)
        ->assertJsonPath('data.territory_name', $assigned->name);
});

it('keeps, rejects, or clears a lead territory on update', function () {
    $user = reportingUser('LeadApiTerritoryUpdate');
    $user->givePermissionTo(leadApiPermissions(['Update:Lead']));
    $status = reportingLeadStatus('Territory Update Status');
    $source = reportingLeadSource('Territory Update Source');
    $assigned = reportingTerritory('Lead Update Assigned');
    $legacy = reportingTerritory('Lead Update Legacy');
    $foreign = reportingTerritory('Lead Update Foreign');
    leadAssignTerritory($user, $assigned);

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'territory_id' => $legacy->id,
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user->fresh());

    $this->patchJson('/api/v1/leads/'.$lead->id, [
        'territory_id' => $legacy->id,
        'description' => 'Unchanged territory',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.territory_id', $legacy->id);

    $this->patchJson('/api/v1/leads/'.$lead->id, ['territory_id' => $foreign->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['territory_id']);
    expect($lead->fresh()->territory_id)->toBe($legacy->id);

    $this->patchJson('/api/v1/leads/'.$lead->id, ['territory_id' => $assigned->id])
        ->assertSuccessful()
        ->assertJsonPath('data.territory_id', $assigned->id);

    $this->patchJson('/api/v1/leads/'.$lead->id, ['territory_id' => null])
        ->assertSuccessful()
        ->assertJsonPath('data.territory_id', null);
});

function leadOwnedContact(User $creator, string $firstName): ContactDetail
{
    $contact = reportingContact($firstName, 'Contact');
    $contact->forceFill(['created_by' => $creator->id])->save();

    return $contact->fresh();
}

it('requires a visible contact when no account is sent on lead create', function () {
    $user = reportingUser('LeadApiStandaloneContact');
    $other = reportingUser('LeadApiStandaloneOther');
    $user->givePermissionTo(leadApiPermissions(['Create:Lead']));
    $status = reportingLeadStatus('Standalone Status');
    $source = reportingLeadSource('Standalone Source');
    $hidden = leadOwnedContact($other, 'Hidden');
    $visible = leadOwnedContact($user, 'Visible');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'contact_detail_id' => $hidden->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id'])
        ->assertJsonMissing(['contact_name' => $hidden->full_name]);
    expect(Lead::query()->where('contact_detail_id', $hidden->id)->exists())->toBeFalse();

    $this->postJson('/api/v1/leads', [
        'status_id' => $status->id,
        'lead_source_id' => $source->id,
        'contact_detail_id' => $visible->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $visible->id);
});

it('keeps an unchanged lead contact but rejects switching to a hidden one', function () {
    $user = reportingUser('LeadApiContactUpdate');
    $other = reportingUser('LeadApiContactUpdateOther');
    $user->givePermissionTo(leadApiPermissions(['Update:Lead']));
    $status = reportingLeadStatus('Contact Update Status');
    $source = reportingLeadSource('Contact Update Source');
    $legacy = leadOwnedContact($other, 'Legacy');
    $hidden = leadOwnedContact($other, 'Foreign');
    $visible = leadOwnedContact($user, 'Mine');

    $lead = Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'contact_detail_id' => $legacy->id,
        'lead_source_id' => $source->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/leads/'.$lead->id, ['description' => 'No contact change'])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $legacy->id);

    $this->patchJson('/api/v1/leads/'.$lead->id, [
        'contact_detail_id' => $legacy->id,
        'description' => 'Same contact sent',
    ])->assertSuccessful();

    $this->patchJson('/api/v1/leads/'.$lead->id, ['contact_detail_id' => $hidden->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id']);
    expect($lead->fresh()->contact_detail_id)->toBe($legacy->id);

    $this->patchJson('/api/v1/leads/'.$lead->id, ['contact_detail_id' => $visible->id])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $visible->id);
});

function leadLifecycleRecord(User $owner, LeadStatus $status, string $description): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'status_id' => $status->id,
        'status_type' => LeadStatus::class,
        'description' => $description,
        'created_by' => $owner->id,
    ]);
}

it('soft deletes a visible lead through the lifecycle action and keeps its history', function () {
    $actor = reportingUser('Lead Lifecycle Owner');
    $actor->givePermissionTo(leadApiPermissions());
    $lead = leadLifecycleRecord($actor, reportingLeadStatus('Lifecycle Open'), 'Selected lead');
    $note = $lead->leadNotes()->create(['note' => 'Keep this history', 'user_id' => $actor->id]);
    Sanctum::actingAs($actor);
    $url = '/api/v1/leads/'.$lead->id;

    $this->getJson($url)->assertOk()->assertJsonPath('data.can_delete', false);
    $this->deleteJson($url)->assertForbidden();
    grantPagePermissions($actor, 'Delete:Lead');
    $this->getJson($url)->assertOk()->assertJsonPath('data.can_delete', true);
    $this->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.0.can_delete', true);
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.deleted', 1);

    $this->assertSoftDeleted($lead);
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'deleted_by' => $actor->id]);
    $this->assertDatabaseHas('lead_notes', ['id' => $note->id, 'lead_id' => $lead->id, 'deleted_at' => null]);
    $this->getJson($url)->assertNotFound();
    $this->getJson($url.'/notes')->assertNotFound();
    $this->getJson('/api/v1/leads')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson($url)->assertNotFound();
});

it('checks the full lead selection and requires an override for approved leads', function () {
    $actor = reportingUser('Lead Bulk Owner');
    $other = reportingUser('Lead Bulk Other');
    $actor->givePermissionTo(leadApiPermissions(['Delete:Lead']));
    $status = reportingLeadStatus('Bulk Lifecycle Open');
    $first = leadLifecycleRecord($actor, $status, 'First permitted lead');
    $approved = leadLifecycleRecord($actor, $status, 'Approved lead');
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => false]);
    $approved->approval()->create([
        'approval_flow_id' => $flow->id, 'requested_by' => $actor->id, 'module' => 'Lead',
        'record_type' => Lead::class, 'record_id' => $approved->id, 'approval_status' => 'approved',
    ]);
    $hidden = leadLifecycleRecord($other, $status, 'Out of scope lead');
    Sanctum::actingAs($actor);
    $url = '/api/v1/leads/bulk-delete';

    $this->deleteJson('/api/v1/leads/'.$hidden->id)->assertNotFound();
    $this->postJson($url, ['ids' => [$first->id, $hidden->id]])->assertNotFound();
    $this->postJson($url, ['ids' => [$first->id, $first->id]])->assertUnprocessable()->assertJsonValidationErrors('ids.1');
    $this->getJson('/api/v1/leads/'.$approved->id)->assertOk()->assertJsonPath('data.can_delete', false);
    $this->postJson($url, ['ids' => [$first->id, $approved->id]])->assertUnprocessable();
    $this->assertNotSoftDeleted($first);
    $this->assertNotSoftDeleted($approved);
    $this->assertNotSoftDeleted($hidden);

    grantPagePermissions($actor, 'OverrideApproval:Lead');
    $this->getJson('/api/v1/leads/'.$approved->id)->assertOk()->assertJsonPath('data.can_delete', true);
    $this->postJson($url, ['ids' => [$first->id, $approved->id]])->assertOk()->assertJsonPath('data.deleted', 2);
    $this->assertSoftDeleted($first);
    $this->assertSoftDeleted($approved);
    $this->assertNotSoftDeleted($hidden);
    $this->assertDatabaseHas('approval_overrides', ['model_type' => Lead::class, 'model_id' => $approved->id, 'user_id' => $actor->id]);
});
