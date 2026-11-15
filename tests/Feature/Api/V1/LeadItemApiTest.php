<?php

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function leadItemApiGrant(User $user, array $extra = []): void
{
    $user->givePermissionTo(array_map(
        fn (string $name) => Permission::findOrCreate($name, 'web'),
        ['ViewAny:Lead', 'View:Lead', 'ViewOwn:Lead', ...$extra],
    ));
}

function leadItemApiLead(User $owner): Lead
{
    return Lead::query()->create([
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'description' => 'Items lead',
        'status_id' => reportingLeadStatus('LI Lead')->id,
        'status_type' => LeadStatus::class,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

it('lists items linked to a visible lead with pivot quantity and price', function () {
    $user = reportingUser('LeadItemApiViewer');
    $other = reportingUser('LeadItemApiOther');
    leadItemApiGrant($user, ['ViewAny:ItemMaster']);
    $lead = leadItemApiLead($user);
    $otherLead = leadItemApiLead($other);
    $tablet = reportingSampleItem('TAB', 'Zeta Tablet');
    $syrup = reportingSampleItem('SYR', 'Alpha Syrup');
    $unlinked = reportingSampleItem('UNL', 'Unlinked Item');
    $lead->itemMasters()->attach($tablet->id, ['quantity' => 3, 'price' => 12.5]);
    $lead->itemMasters()->attach($syrup->id, ['quantity' => 1, 'price' => 99]);
    $otherLead->itemMasters()->attach($unlinked->id, ['quantity' => 9, 'price' => 1]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/items')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.item_master_id', $syrup->id)
        ->assertJsonPath('data.0.item_name', 'Alpha Syrup')
        ->assertJsonPath('data.0.item_code', $syrup->fresh()->item_code)
        ->assertJsonPath('data.0.quantity', 1)
        ->assertJsonPath('data.1.item_master_id', $tablet->id)
        ->assertJsonPath('data.1.quantity', 3)
        ->assertJsonPath('data.1.price', 12.5)
        ->assertJsonMissing(['item_master_id' => $unlinked->id]);
});

it('forbids lead items without ViewAny:ItemMaster', function () {
    $user = reportingUser('LeadItemApiDenied');
    leadItemApiGrant($user);
    $lead = leadItemApiLead($user);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$lead->id.'/items')->assertForbidden();
});

it('returns 404 for items of a hidden lead', function () {
    $user = reportingUser('LeadItemApiHidden');
    $other = reportingUser('LeadItemApiHiddenOther');
    leadItemApiGrant($user, ['ViewAny:ItemMaster']);
    $hiddenLead = leadItemApiLead($other);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leads/'.$hiddenLead->id.'/items')->assertNotFound();
});
