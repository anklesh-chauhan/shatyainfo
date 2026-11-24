<?php

use App\Models\TerritorySalesAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->manager = grantPagePermissions(reportingUser('Territory Assignment Manager'), 'Manage:SalesTargets', 'ViewOwn:AccountMaster');
    $this->rep = User::factory()->create(['name' => 'Replacement User']);
    Sanctum::actingAs($this->manager);
    $this->visible = reportingTerritory('Visible Market');
    $this->hidden = reportingTerritory('Hidden Market');
    reportingDoctor($this->manager, 'Scoped customer')->update(['territory_id' => $this->visible->id]);
    $this->url = '/api/v1/territory-sales-assignments';
    $this->payload = [
        'territory_id' => $this->visible->id,
        'user_id' => $this->rep->id,
        'effective_from' => today()->toDateString(),
        'reason' => 'Transfer approved by sales',
    ];
});

it('negotiates access using effective Sales Targets permissions', function () {
    $menu = '/api/v1/mobile-navigation';
    $entry = ['key' => 'g03-07', 'screen_key' => 'territory-sales-assignments'];
    $this->getJson($menu)->assertOk()->assertJsonMissing(['key' => 'g03-07']);
    $this->getJson($menu.'?destinations=standalone')->assertOk()->assertJsonFragment($entry);
    $this->getJson($this->url)->assertOk()->assertJsonPath('register.can_assign', true);

    $reader = grantPagePermissions(reportingUser('Territory Reader'), 'View:SalesTargets', 'Create:TerritorySalesAssignment');
    Sanctum::actingAs($reader);
    $this->getJson($menu.'?destinations=standalone')->assertOk()->assertJsonFragment($entry);
    $this->getJson($this->url)->assertOk()->assertJsonPath('register.can_assign', false);
    $this->getJson($this->url.'/territories')->assertForbidden();
    $this->getJson($this->url.'/representatives')->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertForbidden();

    $reader->revokePermissionTo('View:SalesTargets');
    grantPagePermissions($reader, 'ViewAny:TerritorySalesAssignment');
    $this->getJson($menu.'?destinations=standalone')->assertOk()->assertJsonMissing(['key' => 'g03-07']);
    $this->getJson($this->url)->assertForbidden();
});

it('lists scoped history and options without exposing historically held territories', function () {
    $initial = $this->postJson($this->url, [
        ...$this->payload,
        'user_id' => $this->manager->id,
        'effective_from' => today()->subDays(4)->toDateString(),
        'reason' => 'Initial ownership',
    ])->assertCreated()->json('data.id');
    $current = $this->postJson($this->url, $this->payload)->assertCreated()->json('data.id');
    TerritorySalesAssignment::query()->create([
        ...$this->payload,
        'territory_id' => $this->hidden->id,
        'user_id' => $this->manager->id,
        'effective_from' => today()->subDays(8),
        'effective_to' => today()->subDays(6),
        'created_by' => $this->manager->id,
    ]);

    $this->getJson($this->url.'?search=Market')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $current)
        ->assertJsonPath('data.0.territory', 'Visible Market')
        ->assertJsonPath('data.0.representative', $this->rep->name)
        ->assertJsonPath('data.0.effective_to', null)
        ->assertJsonPath('data.0.reason', $this->payload['reason'])
        ->assertJsonPath('data.1.id', $initial)
        ->assertJsonPath('data.1.effective_to', today()->subDay()->toDateString())
        ->assertJsonPath('register.today', today()->toDateString());
    $this->getJson($this->url.'?search=Hidden')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->url.'?page=2')->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonCount(0, 'data');
    $this->getJson($this->url.'/territories?search=Market')->assertOk()->assertExactJson([
        'data' => [['id' => $this->visible->id, 'label' => 'Visible Market']],
        'meta' => ['current_page' => 1, 'last_page' => 1],
    ]);
    $this->getJson($this->url.'/representatives?search=Replacement')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->rep->id);
    expect($this->rep->employee)->toBeNull();
    $this->postJson($this->url, [...$this->payload, 'territory_id' => $this->hidden->id])->assertNotFound();
});

it('protects the latest assignment and rechecks permission on replay', function () {
    $first = $this->postJson($this->url, [
        ...$this->payload,
        'user_id' => $this->manager->id,
        'effective_from' => today()->subDays(6)->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->postJson($this->url, [...$this->payload, 'effective_from' => today()->subDay()->toDateString()])->assertUnprocessable();
    $this->postJson($this->url, [...$this->payload, 'user_id' => $this->manager->id])->assertConflict();
    $this->postJson($this->url, [...$this->payload, 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(TerritorySalesAssignment::query()->findOrFail($first)->effective_to)->toBeNull();

    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson($this->url, $this->payload, $headers)->assertCreated();
    $this->postJson($this->url, $this->payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    $this->manager->revokePermissionTo('Manage:SalesTargets');
    $this->postJson($this->url, $this->payload, $headers)->assertForbidden();
    expect(TerritorySalesAssignment::query()->count())->toBe(2);
});
