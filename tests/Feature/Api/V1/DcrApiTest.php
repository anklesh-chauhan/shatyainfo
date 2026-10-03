<?php

use App\Models\SalesDcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns one owned DCR for the requested day', function () {
    $user = reportingUser('DcrApiRep');
    Sanctum::actingAs($user);
    $first = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.user_id', $user->id);
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->assertJsonPath('data.id', $first->json('data.id'));
    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not expose another representatives DCR', function () {
    $owner = reportingUser('DcrOwner');
    Sanctum::actingAs($owner);
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    Sanctum::actingAs(reportingUser('DcrOther'));
    $this->getJson('/api/v1/dcrs/'.$id)->assertNotFound();
});

it('rejects submission of an empty DCR', function () {
    Sanctum::actingAs(reportingUser('EmptyDcr'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('dcr');
});
