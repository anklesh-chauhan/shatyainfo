<?php

use App\Filament\Resources\MarketingCampaigns\Pages\CreateMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\EditMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\ListMarketingCampaigns;
use App\Models\MarketingCampaign;
use App\Models\PromotionalScheme;
use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Services\Marketing\MarketingCampaignQuotaService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Campaign manager');
    $this->outside = reportingUser('Other campaign manager');
    $permissions = [
        'ViewAny:MarketingCampaign', 'View:MarketingCampaign', 'Create:MarketingCampaign', 'Update:MarketingCampaign',
        'Delete:MarketingCampaign', 'Restore:MarketingCampaign', 'ForceDelete:MarketingCampaign',
        'ViewAny:ItemMaster', 'View:ItemMaster', 'ViewAny:Territory', 'View:Territory',
        'ViewAny:PromotionalScheme', 'View:PromotionalScheme',
    ];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo(['ViewAny:MarketingCampaign', 'View:MarketingCampaign']);

    Sanctum::actingAs($this->outside);
    $this->otherItem = reportingSampleItem('CAMP-OTHER', 'Hidden sample');
    $this->otherTerritory = reportingTerritory('Hidden territory');
    $this->otherCampaign = MarketingCampaign::factory()->create(['name' => 'Hidden campaign', 'promotional_scheme_id' => null]);

    Sanctum::actingAs($this->actor);
    $this->item = reportingSampleItem('CAMP-ONE', 'Campaign sample');
    $this->territory = reportingTerritory('Campaign territory');
    $this->scheme = PromotionalScheme::factory()->create(['name' => 'Campaign scheme']);
    $this->path = '/api/v1/marketing-campaigns';
    $this->payload = [
        'name' => 'Field campaign',
        'status' => 'active',
        'start_date' => today()->subDay()->toDateString(),
        'end_date' => today()->addDay()->toDateString(),
        'total_budget' => '300.75',
        'description' => 'Field programme',
        'promotional_scheme_id' => $this->scheme->id,
        'items' => [['item_master_id' => $this->item->id, 'total_quota' => '10.500', 'unit_value' => '20.00']],
        'territory_quotas' => [['territory_id' => $this->territory->id, 'item_master_id' => $this->item->id, 'quota_quantity' => '8.500']],
    ];
});

it('exposes authorized campaigns and validates the same nested choices used by selectors', function () {
    $path = $this->path;
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g06-01', 'screen_key' => 'marketing-campaigns']);
    $this->getJson($path)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('can_create', true);
    $this->getJson($path.'/options/items?search=CAMP')->assertOk()->assertJsonPath('data.0.id', $this->item->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/options/territories')->assertOk()->assertJsonPath('data.0.id', $this->territory->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/options/schemes')->assertOk()->assertJsonPath('data.0.id', $this->scheme->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/'.$this->otherCampaign->id)->assertNotFound();

    $payload = $this->payload;
    $this->postJson($path, [...$payload, 'end_date' => $payload['start_date']])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $this->postJson($path, [...$payload, 'items' => [...$payload['items'], ...$payload['items']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.item_master_id');
    $this->postJson($path, [...$payload, 'items' => [['item_master_id' => $this->otherItem->id, 'total_quota' => 2]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->postJson($path, [...$payload, 'territory_quotas' => [[...$payload['territory_quotas'][0], 'territory_id' => $this->otherTerritory->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors('territory_quotas');
    $this->postJson($path, [...$payload, 'territory_quotas' => [...$payload['territory_quotas'], ...$payload['territory_quotas']]])
        ->assertUnprocessable()->assertJsonValidationErrors('territory_quotas.1.territory_id');
    $this->postJson($path, [...$payload, 'territory_quotas' => [[...$payload['territory_quotas'][0], 'used_quantity' => 100]]])
        ->assertUnprocessable()->assertJsonValidationErrors('territory_quotas.0');

    $created = $this->postJson($path, [...$payload, 'campaign_number' => 'client-number'])->assertCreated()
        ->assertJsonPath('data.items.0.item_name', 'Campaign sample')
        ->assertJsonPath('data.territory_quotas.0.used_quantity', '0.000')
        ->assertJsonPath('data.scheme.name', 'Campaign scheme');
    $id = $created->json('data.id');
    expect($created->json('data.campaign_number'))->not->toBe('client-number');
    $this->getJson($path.'?search=Field&status=active')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonCount(1, 'data');
    $this->getJson($path.'?status=draft')->assertOk()->assertJsonCount(0, 'data');

    $this->actor->revokePermissionTo('View:Territory');
    $this->getJson($path.'/'.$id)->assertOk()->assertJsonPath('data.can_update', false)->assertJsonCount(0, 'data.territory_quotas');
    $this->patchJson($path.'/'.$id, [...$payload, 'revision' => $created->json('data.revision')])->assertForbidden();

    Sanctum::actingAs($this->outside);
    $this->getJson($path.'/options/items')->assertForbidden();
    $this->getJson($path.'/'.$id)->assertNotFound();
    $this->postJson($path, $payload)->assertForbidden();
});

it('preserves posted quota usage and applies revision and bulk action safeguards', function () {
    $payload = $this->payload;
    $path = $this->path;
    $data = $this->postJson($path, $payload)->assertCreated()->json('data');
    $id = $data['id'];
    $detail = $path.'/'.$id;
    $doctor = reportingDoctor($this->actor, 'Campaign sample recipient');
    $distribution = SgipDistribution::query()->create([
        'user_id' => $this->actor->id, 'employee_id' => $this->actor->employee_id, 'account_master_id' => $doctor->id,
        'marketing_campaign_id' => $id, 'territory_id' => $this->territory->id, 'visit_date' => today(), 'approval_status' => 'draft',
    ]);
    $line = SgipDistributionItem::query()->create([
        'sgip_distribution_id' => $distribution->id, 'item_master_id' => $this->item->id, 'quantity' => 4, 'unit_value' => 20,
    ]);
    $quotas = app(MarketingCampaignQuotaService::class);
    $quotas->validate($distribution);
    $quotas->consumeQuota($distribution);
    $this->getJson($detail)->assertOk()->assertJsonPath('data.revision', $data['revision'])
        ->assertJsonPath('data.territory_quotas.0.used_quantity', '4.000');

    $edit = [...$payload, 'revision' => $data['revision'], 'name' => 'Renamed campaign'];
    $this->patchJson($detail, [...$edit, 'territory_quotas' => []])->assertUnprocessable()->assertJsonValidationErrors('territory_quotas');
    $this->patchJson($detail, [...$edit, 'territory_quotas' => [[...$edit['territory_quotas'][0], 'quota_quantity' => 3]]])
        ->assertUnprocessable()->assertJsonValidationErrors('territory_quotas.0.quota_quantity');
    $this->getJson($detail)->assertOk()->assertJsonPath('data.name', 'Field campaign');
    $saved = $this->patchJson($detail, [...$edit, 'territory_quotas' => [[...$edit['territory_quotas'][0], 'quota_quantity' => '5.000']]])
        ->assertOk()->assertJsonPath('data.name', 'Renamed campaign')->assertJsonPath('data.territory_quotas.0.used_quantity', '4.000');
    expect($saved->json('data.revision'))->not->toBe($data['revision']);
    $this->patchJson($detail, $edit)->assertConflict();
    $line->update(['quantity' => 2]);
    expect(fn () => $quotas->validate($distribution->fresh()))->toThrow(ValidationException::class);

    $unused = $this->postJson($path, [...$payload, 'name' => 'Unused campaign'])->assertCreated()->json('data.id');
    $this->postJson($path.'/records/delete', ['ids' => [$id, $this->otherCampaign->id]])->assertNotFound();
    $this->postJson($path.'/records/delete', ['ids' => [$id, $unused]])->assertOk()->assertJsonPath('data.count', 2);
    $this->getJson($path.'?trash=trashed')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson($path.'/records/force-delete', ['ids' => [$unused, $id]])->assertConflict();
    $this->getJson($path.'/'.$unused)->assertOk()->assertJsonPath('data.trashed', true);
    $this->postJson($path.'/records/force-delete', ['ids' => [$unused]])->assertOk();
    $this->getJson($path.'/'.$unused)->assertNotFound();
    $this->postJson($path.'/records/restore', ['ids' => [$id]])->assertOk();
    $this->getJson($detail)->assertOk()->assertJsonPath('data.territory_quotas.0.used_quantity', '4.000')->assertJsonPath('data.trashed', false);
});

it('uses transactional campaign configuration from the Admin create and edit pages', function () {
    $this->withoutExceptionHandling();
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateMarketingCampaign::class)->assertSuccessful()->fillForm($this->payload)->call('create')->assertHasNoFormErrors();
    $campaign = MarketingCampaign::query()->where('name', $this->payload['name'])->sole();
    expect($campaign->items()->count())->toBe(1)->and($campaign->territoryQuotas()->count())->toBe(1);
    $quota = $campaign->territoryQuotas()->sole();
    $quota->update(['used_quantity' => 2]);

    Livewire::test(EditMarketingCampaign::class, ['record' => $campaign->getRouteKey()])->assertSuccessful()
        ->fillForm(['name' => 'Admin campaign revision'])->call('save')->assertHasNoFormErrors();
    expect($campaign->refresh()->name)->toBe('Admin campaign revision')
        ->and($quota->fresh()->used_quantity)->toBe('2.000');

    Livewire::test(ListMarketingCampaigns::class)->selectTableRecords([$campaign])
        ->callAction(TestAction::make('delete')->table()->bulk());
    $this->assertSoftDeleted($campaign);
    $this->assertDatabaseHas('marketing_campaign_territory_quotas', ['id' => $quota->id, 'used_quantity' => 2]);
});
