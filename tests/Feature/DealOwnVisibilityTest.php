<?php

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('scopes ViewOwn deals by owner_id for owned and unrelated records', function () {
    $owner = reportingUser('DealOwner');
    $other = reportingUser('DealOther');
    $owner->givePermissionTo(Permission::findOrCreate('ViewOwn:Deal', 'web'));
    $stage = reportingDealStage('Open');

    // created_by is another user so visibility must come from owner_id.
    $owned = Deal::query()->create([
        'reference_code' => 'DL-OWN-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Owned Deal',
        'transaction_date' => today(),
        'owner_id' => $owner->id,
        'amount' => 1000,
        'expected_revenue' => 1000,
        'expected_close_date' => today()->addDays(7),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $unrelated = Deal::query()->create([
        'reference_code' => 'DL-OTH-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Other Deal',
        'transaction_date' => today(),
        'owner_id' => $other->id,
        'amount' => 500,
        'expected_revenue' => 500,
        'expected_close_date' => today()->addDays(7),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $this->actingAs($owner);

    $ids = Deal::query()->applyVisibility('Deal')->pluck('id');

    expect($ids)->toContain($owned->id)
        ->and($ids)->not->toContain($unrelated->id);
});

it('does not crash ViewOwn for a user without an employee mapping', function () {
    $user = User::factory()->create([
        'name' => 'DealNoEmployee',
        'email' => 'deal-no-employee-'.fake()->unique()->numberBetween(1000, 9999).'@example.test',
        'employee_id' => null,
    ]);
    $user->givePermissionTo(Permission::findOrCreate('ViewOwn:Deal', 'web'));
    $stage = reportingDealStage('Open');
    $other = reportingUser('DealOtherOwner');

    $owned = Deal::query()->create([
        'reference_code' => 'DL-NE-'.fake()->unique()->numerify('####'),
        'deal_name' => 'No Employee Deal',
        'transaction_date' => today(),
        'owner_id' => $user->id,
        'amount' => 250,
        'expected_revenue' => 250,
        'expected_close_date' => today()->addDays(3),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    Deal::query()->create([
        'reference_code' => 'DL-NEO-'.fake()->unique()->numerify('####'),
        'deal_name' => 'Someone Else',
        'transaction_date' => today(),
        'owner_id' => $other->id,
        'amount' => 100,
        'expected_revenue' => 100,
        'expected_close_date' => today()->addDays(3),
        'status_id' => $stage->id,
        'status_type' => DealStage::class,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect($user->employee)->toBeNull();

    $this->actingAs($user);

    $ids = Deal::query()->applyVisibility('Deal')->pluck('id');

    expect($ids)->toContain($owned->id)
        ->and($ids)->toHaveCount(1);
});
