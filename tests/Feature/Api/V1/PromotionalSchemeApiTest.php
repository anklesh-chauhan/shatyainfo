<?php

use App\Filament\Resources\PromotionalSchemes\Pages\CreatePromotionalScheme;
use App\Filament\Resources\PromotionalSchemes\Pages\EditPromotionalScheme;
use App\Models\MarketingCampaign;
use App\Models\PromotionalScheme;
use App\Models\SalesDocument;
use App\Services\Marketing\PromotionalSchemeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Scheme manager');
    $this->outside = reportingUser('Other scheme manager');
    $permissions = [
        'ViewAny:PromotionalScheme', 'View:PromotionalScheme', 'Create:PromotionalScheme', 'Update:PromotionalScheme',
        'Delete:PromotionalScheme', 'Restore:PromotionalScheme', 'ForceDelete:PromotionalScheme',
        'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewAny:Territory', 'View:Territory',
        'ViewAny:ItemMaster', 'View:ItemMaster',
    ];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo(['ViewAny:PromotionalScheme', 'View:PromotionalScheme']);

    Sanctum::actingAs($this->outside);
    $this->outsideCustomer = reportingDoctor($this->outside, 'Hidden scheme account');
    $this->outsideItem = reportingSampleItem('HIDDEN-SCHEME', 'Hidden scheme item');
    $this->outsideScheme = PromotionalScheme::factory()->create();

    Sanctum::actingAs($this->actor);
    $this->customer = reportingDoctor($this->actor, 'Visible scheme account');
    $this->territory = reportingTerritory('Scheme territory');
    $this->item = reportingSampleItem('SCHEME', 'Visible scheme item');
    $this->path = '/api/v1/promotional-schemes';
    $this->payload = [
        'code' => 'Q4-SCHEME', 'name' => 'Quarterly offer', 'status' => 'active', 'scheme_type' => 'slab_discount',
        'applies_to' => 'customer', 'applies_to_id' => $this->customer->id,
        'valid_from' => today()->toDateString(), 'valid_to' => today()->toDateString(), 'min_order_value' => '500.00',
        'benefits' => [['benefit_type' => 'discount_percent', 'item_master_id' => $this->item->id,
            'discount_value' => '7.5000', 'min_quantity' => '5.000', 'max_quantity' => '10.000', 'remarks' => 'First tier']],
    ];
});

it('scopes the register and all three target catalogs, and rejects forged benefit items', function () {
    $path = $this->path;
    $p = $this->payload;
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g06-02', 'screen_key' => 'promotional-schemes']);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('can_create', true);
    $this->getJson($path.'/options/customer')->assertOk()->assertJsonPath('data.0.id', $this->customer->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/options/territory')->assertOk()->assertJsonPath('data.0.id', $this->territory->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/options/item?search=SCHEME')->assertOk()->assertJsonPath('data.0.id', $this->item->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/'.$this->outsideScheme->id)->assertNotFound();
    $this->postJson($path, [...$p, 'applies_to_id' => $this->outsideCustomer->id])->assertUnprocessable()->assertJsonValidationErrors('applies_to_id');
    $this->postJson($path, [...$p, 'applies_to' => 'global'])->assertUnprocessable()->assertJsonValidationErrors('applies_to_id');
    $this->postJson($path, [...$p, 'valid_to' => today()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_to');
    $this->postJson($path, [...$p, 'benefits' => [[...$p['benefits'][0], 'item_master_id' => $this->outsideItem->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('benefits');
    $this->postJson($path, [...$p, 'benefits' => [[...$p['benefits'][0], 'max_quantity' => 4]]])
        ->assertUnprocessable()->assertJsonValidationErrors('benefits.0.max_quantity');

    $created = $this->postJson($path, $p)->assertCreated()->assertJsonPath('data.target.label', 'Visible scheme account')
        ->assertJsonPath('data.benefits.0.discount_value', '7.5000')->json('data');
    $this->postJson($path, $p)->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson($path.'?search=Q4&status=active')->assertOk()->assertJsonPath('data.0.id', $created['id'])->assertJsonCount(1, 'data');
    $this->getJson($path.'?status=draft')->assertOk()->assertJsonCount(0, 'data');

    $this->actor->revokePermissionTo('View:ItemMaster');
    $detail = $path.'/'.$created['id'];
    $this->getJson($detail)->assertOk()->assertJsonPath('data.can_update', false)->assertJsonCount(0, 'data.benefits');
    $this->patchJson($detail, [...$p, 'revision' => $created['revision']])->assertForbidden();
    Sanctum::actingAs($this->outside);
    $this->getJson($path.'/options/item')->assertForbidden();
    $this->getJson($detail)->assertNotFound();
    $this->postJson($path, $p)->assertForbidden();
});

it('updates owned benefit rows and applies scoped record actions atomically', function () {
    $p = $this->payload;
    $path = $this->path;
    $original = $this->postJson($path, $p)->assertCreated()->json('data');
    $other = $this->postJson($path, [...$p, 'code' => 'ANOTHER'])->assertCreated()->json('data');
    $id = $original['id'];
    $detail = $path.'/'.$id;
    $edit = [...$p, 'revision' => $original['revision'], 'name' => 'Updated offer'];
    $this->patchJson($detail, [...$edit, 'benefits' => [[...$p['benefits'][0], 'id' => $other['benefits'][0]['id']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('benefits.0.id');
    $this->patchJson($detail, [...$edit, 'benefits' => [[...$p['benefits'][0], 'id' => $original['benefits'][0]['id'], 'discount_value' => 8]]])
        ->assertOk()->assertJsonPath('data.benefits.0.id', $original['benefits'][0]['id'])->assertJsonPath('data.benefits.0.discount_value', '8.0000');
    $this->patchJson($detail, $edit)->assertConflict();

    MarketingCampaign::factory()->create(['promotional_scheme_id' => $id]);
    $this->postJson($path.'/records/delete', ['ids' => [$id, $this->outsideScheme->id]])->assertNotFound();
    $this->postJson($path.'/records/delete', ['ids' => [$id, $other['id']]])->assertOk()->assertJsonPath('data.count', 2);
    $this->getJson($path.'?trash=trashed')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson($path.'/records/force-delete', ['ids' => [$other['id'], $id]])->assertConflict();
    $this->postJson($path.'/records/force-delete', ['ids' => [$other['id']]])->assertOk();
    $this->getJson($path.'/'.$other['id'])->assertNotFound();
    $this->postJson($path.'/records/restore', ['ids' => [$id]])->assertOk();
    $this->getJson($detail)->assertOk()->assertJsonPath('data.trashed', false);
});

it('shares the scoped configuration and stable benefit rows with Admin', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreatePromotionalScheme::class)->assertSuccessful()
        ->fillForm($this->payload)->call('create')->assertHasNoFormErrors();
    $scheme = PromotionalScheme::query()->where('code', 'Q4-SCHEME')->sole();
    $benefit = $scheme->benefits()->sole();
    Livewire::test(EditPromotionalScheme::class, ['record' => $scheme->getRouteKey()])->assertSuccessful()
        ->fillForm(['name' => 'Admin edit'])->call('save')->assertHasNoFormErrors()
        ->fillForm(['name' => 'Admin edit again', 'applies_to' => 'global', 'applies_to_id' => null])->call('save')->assertHasNoFormErrors();
    expect($scheme->refresh()->name)->toBe('Admin edit again')
        ->and($scheme->applies_to_id)->toBeNull()
        ->and($scheme->benefits()->sole()->id)->toBe($benefit->id);
});

it('resolves the current scheme at date and order boundaries and returns configured tiers', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $doc = (new SalesDocument)->forceFill(['date' => today(), 'account_master_id' => $this->customer->id, 'total' => '500.00']);
    $resolver = app(PromotionalSchemeService::class);
    $result = $resolver->calculateBenefits($doc);
    expect($result)->toHaveCount(1)->and($result[0]['scheme']->id)->toBe($id)
        ->and($result[0]['benefits'][0]['min_quantity'])->toBe('5.000')
        ->and($result[0]['benefits'][0]['max_quantity'])->toBe('10.000');
    $doc->total = '499.99';
    expect($resolver->calculateBenefits($doc))->toBe([]);
    $doc->total = '500.00';
    $doc->date = today()->addDay();
    expect($resolver->calculateBenefits($doc))->toBe([]);
    $doc->date = today();
    $doc->account_master_id = $this->outsideCustomer->id;
    expect($resolver->calculateBenefits($doc))->toBe([]);
    $doc->account_master_id = $this->customer->id;
    $this->postJson($this->path.'/records/delete', ['ids' => [$id]])->assertOk();
    expect($resolver->calculateBenefits($doc))->toBe([]);
});
