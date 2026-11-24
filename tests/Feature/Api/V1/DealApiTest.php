<?php

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\ContactDetail;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\TypeMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function dealApiPermissions(array $extra = []): array
{
    return array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        [
            'ViewAny:Deal',
            'View:Deal',
            'ViewOwn:Deal',
            ...$extra,
        ],
    );
}

function dealAccountPermissions(): array
{
    return [
        Permission::findOrCreate('ViewAny:AccountMaster', 'web'),
        Permission::findOrCreate('View:AccountMaster', 'web'),
        Permission::findOrCreate('ViewOwn:AccountMaster', 'web'),
    ];
}

function reportingDealType(string $name): TypeMaster
{
    return TypeMaster::query()->firstOrCreate(
        ['name' => $name, 'typeable_type' => Deal::class],
        ['parent_id' => null],
    );
}

it('soft deletes only visible deals with delete permission and keeps linked follow-ups', function () {
    $owner = reportingUser('Deal Delete Owner');
    $other = reportingUser('Deal Delete Other');
    $owner->givePermissionTo(dealApiPermissions());
    $stage = reportingDealStage('Delete pipeline');
    $owned = Deal::query()->create([
        'deal_name' => 'Owned deal',
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'created_by' => $other->id,
        'amount' => 100,
        'expected_revenue' => 75,
        'expected_close_date' => today(),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
    ]);
    $hidden = $owned->replicate(['reference_code'])->fill(['owner_id' => $other->id]);
    $hidden->save();
    $followUp = $owned->followUps()->create(['user_id' => $owner->id, 'follow_up_date' => now()]);
    $path = '/api/v1/deals';

    Sanctum::actingAs($owner);
    $this->getJson($path)->assertOk()->assertJsonPath('data.0.can_delete', false);
    $this->deleteJson($path.'/'.$owned->id)->assertForbidden();
    $this->assertNotSoftDeleted($owned);

    $owner->givePermissionTo(Permission::findOrCreate('Delete:Deal', 'web'));
    $this->getJson($path.'/'.$owned->id)->assertOk()->assertJsonPath('data.can_delete', true);
    $this->deleteJson($path.'/'.$hidden->id)->assertNotFound();
    $this->deleteJson($path.'/'.$owned->id)->assertOk()->assertJsonPath('data.deleted', 1);
    $this->assertSoftDeleted('deals', ['id' => $owned->id, 'deleted_by' => $owner->id]);
    $this->assertNotSoftDeleted($hidden);
    $this->assertDatabaseHas('follow_ups', [
        'id' => $followUp->id, 'followupable_id' => $owned->id, 'followupable_type' => Deal::class, 'deleted_at' => null,
    ]);
    $this->getJson($path.'/'.$owned->id)->assertNotFound();
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
});

it('validates bulk Deal deletion atomically and audits a legacy approval override', function () {
    $owner = reportingUser('Deal Bulk Owner');
    $other = reportingUser('Deal Bulk Other');
    $owner->givePermissionTo(dealApiPermissions(['Delete:Deal']));
    $stage = reportingDealStage('Bulk delete pipeline');
    $first = Deal::query()->create([
        'deal_name' => 'Bulk deal',
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'created_by' => $other->id,
        'amount' => 100,
        'expected_revenue' => 75,
        'expected_close_date' => today(),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
    ]);
    $hidden = $first->replicate(['reference_code'])->fill(['owner_id' => $other->id]);
    $hidden->save();
    $approved = $first->replicate(['reference_code']);
    $approved->save();
    $flow = ApprovalFlow::query()->create(['module' => 'SalesTourPlan', 'active' => false]);
    $approval = $approved->approval()->create([
        'approval_flow_id' => $flow->id, 'requested_by' => $owner->id, 'module' => 'Deal',
        'record_type' => Deal::class, 'record_id' => $approved->id, 'approval_status' => 'approved',
    ]);
    expect(ApprovalSetting::supportsModule('Deal'))->toBeFalse();

    Sanctum::actingAs($owner);
    $path = '/api/v1/deals/bulk-delete';
    $this->postJson($path, ['ids' => [$first->id, $first->id]])->assertUnprocessable()->assertJsonValidationErrors('ids.1');
    $this->postJson($path, ['ids' => [$first->id, $hidden->id]])->assertNotFound();
    $this->getJson('/api/v1/deals/'.$approved->id)->assertOk()->assertJsonPath('data.can_delete', false);
    $this->postJson($path, ['ids' => [$first->id, $approved->id]])->assertUnprocessable();
    $this->assertNotSoftDeleted($first);
    $this->assertNotSoftDeleted($approved);

    $owner->givePermissionTo(Permission::findOrCreate('OverrideApproval:Deal', 'web'));
    $this->getJson('/api/v1/deals/'.$approved->id)->assertOk()->assertJsonPath('data.can_delete', true);
    $this->postJson($path, ['ids' => [$first->id, $approved->id]])->assertOk()->assertJsonPath('data.deleted', 2);
    $this->assertSoftDeleted($first);
    $this->assertSoftDeleted($approved);
    $this->assertNotSoftDeleted($hidden);
    $this->assertDatabaseHas('approvals', ['id' => $approval->id, 'approval_status' => 'approved']);
    $this->assertDatabaseHas('approval_overrides', [
        'model_type' => Deal::class, 'model_id' => $approved->id, 'user_id' => $owner->id,
        'reason' => 'Deal deleted through the mobile API',
    ]);
});

it('lists and shows owned deals and hides unrelated ones', function () {
    $owner = reportingUser('DealApiOwner');
    $other = reportingUser('DealApiOther');
    $owner->givePermissionTo(dealApiPermissions());
    $stage = reportingDealStage('Pipeline');

    $owned = Deal::query()->create([
        'reference_code' => 'DL-API-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Owned Clinic Deal',
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'amount' => 1500,
        'expected_revenue' => 1500,
        'expected_close_date' => today()->addDays(10),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $hidden = Deal::query()->create([
        'reference_code' => 'DL-HID-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Hidden Deal',
        'transaction_date' => today(),
        'owner_id' => $other->id,
        'amount' => 900,
        'expected_revenue' => 900,
        'expected_close_date' => today()->addDays(5),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/deals')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $owned->id)
        ->assertJsonMissing(['id' => $hidden->id]);

    $this->getJson('/api/v1/deals/'.$owned->id)
        ->assertSuccessful()
        ->assertJsonPath('data.deal_name', 'Owned Clinic Deal')
        ->assertJsonPath('data.owner_id', $owner->id);

    $this->getJson('/api/v1/deals/'.$hidden->id)
        ->assertNotFound();
});

it('rejects deal list without ViewAny', function () {
    Sanctum::actingAs(reportingUser('DealApiDenied'));

    $this->getJson('/api/v1/deals')->assertForbidden();
});

it('creates an owned deal for the authenticated user', function () {
    $user = reportingUser('DealApiCreate');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal']));
    $stage = reportingDealStage('Qualification');
    $type = reportingDealType('New Business');
    $account = reportingDoctor($user, 'Dr Deal Account');
    $user->givePermissionTo(dealAccountPermissions());

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Field Clinic Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 2500,
        'expected_revenue' => 2000,
        'expected_close_date' => today()->addDays(14)->toDateString(),
        'account_master_id' => $account->id,
        'description' => 'Opened from mobile',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.deal_name', 'Field Clinic Deal')
        ->assertJsonPath('data.owner_id', $user->id)
        ->assertJsonPath('data.account_master_id', $account->id)
        ->assertJsonPath('data.status_id', $stage->id)
        ->assertJsonPath('data.type_id', $type->id)
        ->assertJsonPath('data.amount', 2500);

    $deal = Deal::query()->where('deal_name', 'Field Clinic Deal')->sole();
    expect($deal->owner_id)->toBe($user->id)
        ->and($deal->status_type)->toBe(DealStage::class)
        ->and($deal->type_type)->toBe(TypeMaster::class)
        ->and($deal->reference_code)->not->toBeEmpty();
});

it('forbids deal create without Create permission', function () {
    $user = reportingUser('DealApiCreateDenied');
    $user->givePermissionTo(dealApiPermissions());
    $stage = reportingDealStage('Denied Stage');
    $type = reportingDealType('Denied Type');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Forbidden Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay()->toDateString(),
    ])->assertForbidden();
});

it('updates a visible owned deal', function () {
    $user = reportingUser('DealApiUpdate');
    $user->givePermissionTo(dealApiPermissions(['Update:Deal']));
    $stage = reportingDealStage('Open');
    $nextStage = reportingDealStage('Proposal');
    $type = reportingDealType('Expansion');

    $deal = Deal::query()->create([
        'reference_code' => 'DL-UPD-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Before Update',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'amount' => 500,
        'expected_revenue' => 500,
        'expected_close_date' => today()->addDays(7),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'type_id' => $type->id,
        'type_type' => TypeMaster::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/deals/'.$deal->id, [
        'deal_name' => 'After Update',
        'amount' => 750,
        'status_id' => $nextStage->id,
        'description' => 'Revised on mobile',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.deal_name', 'After Update')
        ->assertJsonPath('data.amount', 750)
        ->assertJsonPath('data.status_id', $nextStage->id)
        ->assertJsonPath('data.description', 'Revised on mobile');

    expect($deal->fresh()->owner_id)->toBe($user->id);
});

it('does not update an unrelated deal', function () {
    $owner = reportingUser('DealApiUpdateOwner');
    $other = reportingUser('DealApiUpdateOther');
    $other->givePermissionTo(dealApiPermissions(['Update:Deal']));
    $stage = reportingDealStage('Hidden Update');

    $deal = Deal::query()->create([
        'reference_code' => 'DL-HIDU-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Someone Else Deal',
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'amount' => 300,
        'expected_revenue' => 300,
        'expected_close_date' => today()->addDays(3),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    Sanctum::actingAs($other);

    $this->patchJson('/api/v1/deals/'.$deal->id, [
        'deal_name' => 'Hijacked',
    ])->assertNotFound();
});

it('returns authorized deal form options and edit capabilities', function () {
    $user = reportingUser('DealApiOptions');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal', 'Update:Deal']));
    $stage = reportingDealStage('Option Stage');
    $type = reportingDealType('Option Type');
    $account = reportingDoctor($user, 'Dr Options');
    $contact = reportingContact('Option', 'Contact');
    $account->contactDetails()->attach($contact->id);
    $address = Address::query()->create([
        'street' => '12 Option Street',
        'pin_code' => '400001',
        'addressable_id' => $account->id,
        'addressable_type' => AccountMaster::class,
        'is_primary' => true,
    ]);

    $deal = Deal::query()->create([
        'reference_code' => 'DL-OPT-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Capability Deal',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'account_master_id' => $account->id,
        'contact_detail_id' => $contact->id,
        'address_id' => $address->id,
        'amount' => 400,
        'expected_revenue' => 400,
        'expected_close_date' => today()->addDays(4),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'type_id' => $type->id,
        'type_type' => TypeMaster::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/deals/options')
        ->assertSuccessful()
        ->assertJsonPath('data.can_create', true)
        ->assertJsonFragment(['id' => $stage->id, 'name' => $stage->name])
        ->assertJsonFragment(['id' => $type->id, 'name' => $type->name]);

    $this->getJson('/api/v1/deals/'.$deal->id)
        ->assertSuccessful()
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.status_id', $stage->id)
        ->assertJsonPath('data.type_id', $type->id)
        ->assertJsonPath('data.account_master_id', $account->id)
        ->assertJsonPath('data.contact_detail_id', $contact->id)
        ->assertJsonPath('data.address_id', $address->id)
        ->assertJsonPath('data.address_street', '12 Option Street');
});

it('marks can_create and can_update false without write permissions', function () {
    $user = reportingUser('DealApiCapsDenied');
    $user->givePermissionTo(dealApiPermissions());
    $stage = reportingDealStage('Read Stage');
    $type = reportingDealType('Read Type');

    $deal = Deal::query()->create([
        'reference_code' => 'DL-CAP-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Read Only Deal',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay(),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'type_id' => $type->id,
        'type_type' => TypeMaster::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/deals/options')
        ->assertSuccessful()
        ->assertJsonPath('data.can_create', false);

    $this->getJson('/api/v1/deals/'.$deal->id)
        ->assertSuccessful()
        ->assertJsonPath('data.can_update', false);
});

it('rejects create with an invisible account', function () {
    $owner = reportingUser('DealApiAcctOwner');
    $user = reportingUser('DealApiAcctDenied');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal']));
    $user->givePermissionTo(dealAccountPermissions());
    $stage = reportingDealStage('Hidden Acct Stage');
    $type = reportingDealType('Hidden Acct Type');
    $hiddenAccount = reportingDoctor($owner, 'Dr Hidden Account');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Invisible Account Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay()->toDateString(),
        'account_master_id' => $hiddenAccount->id,
    ])->assertNotFound();
});

it('rejects create when contact or address relations are invalid', function () {
    $user = reportingUser('DealApiRelation');
    $other = reportingUser('DealApiRelationOther');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal']));
    $user->givePermissionTo(dealAccountPermissions());
    $stage = reportingDealStage('Relation Stage');
    $type = reportingDealType('Relation Type');
    $account = reportingDoctor($user, 'Dr Relation Account');
    $unlinkedContact = reportingContact('Unlinked', 'Buyer');
    $foreignAccount = reportingDoctor($other, 'Dr Foreign');
    $foreignAddress = Address::query()->create([
        'street' => '99 Foreign Lane',
        'pin_code' => '400002',
        'addressable_id' => $foreignAccount->id,
        'addressable_type' => AccountMaster::class,
        'is_primary' => true,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Bad Contact Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay()->toDateString(),
        'account_master_id' => $account->id,
        'contact_detail_id' => $unlinkedContact->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id']);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Bad Address Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay()->toDateString(),
        'account_master_id' => $account->id,
        'address_id' => $foreignAddress->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address_id']);

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Orphan Address Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDay()->toDateString(),
        'address_id' => $foreignAddress->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address_id']);
});

it('creates a deal with valid account contact and address relations', function () {
    $user = reportingUser('DealApiValidRel');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal']));
    $user->givePermissionTo(dealAccountPermissions());
    $stage = reportingDealStage('Valid Rel Stage');
    $type = reportingDealType('Valid Rel Type');
    $account = reportingDoctor($user, 'Dr Valid Rel');
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

    $this->postJson('/api/v1/deals', [
        'deal_name' => 'Valid Relation Deal',
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 880,
        'expected_revenue' => 800,
        'expected_close_date' => today()->addDays(9)->toDateString(),
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

function dealOwnedContact(User $creator, string $firstName): ContactDetail
{
    $contact = reportingContact($firstName, 'Contact');
    $contact->forceFill(['created_by' => $creator->id])->save();

    return $contact->fresh();
}

it('requires a visible contact when no account is sent on deal create', function () {
    $user = reportingUser('DealApiStandaloneContact');
    $other = reportingUser('DealApiStandaloneOther');
    $user->givePermissionTo(dealApiPermissions(['Create:Deal']));
    $stage = reportingDealStage('Standalone Stage');
    $type = reportingDealType('Standalone Type');
    $hidden = dealOwnedContact($other, 'Hidden');
    $visible = dealOwnedContact($user, 'Visible');
    $payload = [
        'status_id' => $stage->id,
        'type_id' => $type->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDays(5)->toDateString(),
    ];

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/deals', [...$payload, 'deal_name' => 'Hidden Contact Deal', 'contact_detail_id' => $hidden->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id']);
    expect(Deal::query()->where('deal_name', 'Hidden Contact Deal')->exists())->toBeFalse();

    $this->postJson('/api/v1/deals', [...$payload, 'deal_name' => 'Visible Contact Deal', 'contact_detail_id' => $visible->id])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $visible->id);
});

it('keeps an unchanged deal contact but rejects switching to a hidden one', function () {
    $user = reportingUser('DealApiContactUpdate');
    $other = reportingUser('DealApiContactUpdateOther');
    $user->givePermissionTo(dealApiPermissions(['Update:Deal']));
    $stage = reportingDealStage('Contact Update Stage');
    $type = reportingDealType('Contact Update Type');
    $legacy = dealOwnedContact($other, 'Legacy');
    $hidden = dealOwnedContact($other, 'Foreign');
    $visible = dealOwnedContact($user, 'Mine');

    $deal = Deal::query()->create([
        'reference_code' => 'DL-CON-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Contact Update Deal',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'contact_detail_id' => $legacy->id,
        'amount' => 500,
        'expected_revenue' => 500,
        'expected_close_date' => today()->addDays(7),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'type_id' => $type->id,
        'type_type' => TypeMaster::class,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/deals/'.$deal->id, ['description' => 'No contact change'])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $legacy->id);

    $this->patchJson('/api/v1/deals/'.$deal->id, ['contact_detail_id' => $hidden->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_detail_id']);
    expect($deal->fresh()->contact_detail_id)->toBe($legacy->id);

    $this->patchJson('/api/v1/deals/'.$deal->id, ['contact_detail_id' => $visible->id])
        ->assertSuccessful()
        ->assertJsonPath('data.contact_detail_id', $visible->id);
});
