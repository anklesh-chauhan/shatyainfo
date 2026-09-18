<?php

use App\Enums\ItemType;
use App\Models\ItemMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lists sgip catalog items for the field app', function () {
    $user = reportingUser('ItemCatalog');
    $sample = reportingSampleItem('CAT', 'Amox Sample');
    $gift = ItemMaster::query()->create([
        'item_name' => 'Doctor Gift Pen',
        'item_type' => ItemType::Gift,
        'selling_price' => 25,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/items')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $sample->id, 'item_name' => 'Amox Sample'])
        ->assertJsonFragment(['id' => $gift->id, 'item_name' => 'Doctor Gift Pen']);
});

it('filters catalog by item type', function () {
    $user = reportingUser('ItemFilter');
    $sample = reportingSampleItem('FIL', 'Filter Sample');
    ItemMaster::query()->create([
        'item_name' => 'Filter Gift',
        'item_type' => ItemType::Gift,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/items?type=sample')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $sample->id])
        ->assertJsonMissing(['item_name' => 'Filter Gift']);
});

it('searches catalog by name', function () {
    $user = reportingUser('ItemSearch');
    reportingSampleItem('SRC', 'Zinco Sample');
    $match = reportingSampleItem('SRC', 'Beta Sample Pack');

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/items?search=Beta')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $match->id])
        ->assertJsonMissing(['item_name' => 'Zinco Sample']);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/items')->assertUnauthorized();
});
