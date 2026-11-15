<?php

use App\Models\ContactDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function contactApiCreatedBy(User $creator, string $firstName, ?string $mobile = null): ContactDetail
{
    $contact = ContactDetail::query()->create([
        'first_name' => $firstName,
        'last_name' => 'Contact',
        'mobile_number' => $mobile,
    ]);
    $contact->forceFill(['created_by' => $creator->id])->save();

    return $contact->fresh();
}

it('forbids the contact list without ViewAny:ContactDetail', function () {
    Sanctum::actingAs(reportingUser('ContactApiDenied'));

    $this->getJson('/api/v1/contacts')->assertForbidden();
});

it('lists only visible contacts with picker fields', function () {
    $user = reportingUser('ContactApiViewer');
    $other = reportingUser('ContactApiOther');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:ContactDetail', 'web'));
    $mine = contactApiCreatedBy($user, 'Asha', '9000000101');
    $hidden = contactApiCreatedBy($other, 'Hidden');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.full_name', 'Asha Contact')
        ->assertJsonPath('data.0.mobile_number', '9000000101')
        ->assertJsonMissing(['id' => $hidden->id])
        ->assertJsonMissingPath('data.0.notes')
        ->assertJsonPath('meta.total', 1);
});

it('searches visible contacts by name or mobile', function () {
    $user = reportingUser('ContactApiSearch');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:ContactDetail', 'web'));
    $asha = contactApiCreatedBy($user, 'Asha', '9000000201');
    $bhavna = contactApiCreatedBy($user, 'Bhavna', '9000000202');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts?search=Bhav')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bhavna->id);

    $this->getJson('/api/v1/contacts?search=0201')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asha->id);
});

it('lists every contact for AccessAllRecords', function () {
    $user = reportingUser('ContactApiAll');
    $other = reportingUser('ContactApiAllOther');
    $user->givePermissionTo([
        Permission::findOrCreate('ViewAny:ContactDetail', 'web'),
        Permission::findOrCreate('AccessAllRecords', 'web'),
    ]);
    $foreign = contactApiCreatedBy($other, 'Foreign');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/contacts?search=Foreign')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $foreign->id);
});
