<?php

use App\Filament\GlobalSearch\BusinessSearchProvider;
use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Models\ContactDetail;
use App\Models\ItemMaster;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->user = reportingUser('Search Rep');
    $this->actingAs($this->user);
    $this->search = app(BusinessSearchProvider::class);
});

function allowBusinessSearch(User $user): void
{
    $user->givePermissionTo(Permission::findOrCreate('AccessAllRecords', 'web'));
    foreach (array_keys(app(BusinessSearchProvider::class)->profiles()) as $model) {
        foreach (['ViewAny', 'View'] as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability.':'.class_basename($model), 'web'));
        }
    }
}

it('searches the business catalog without querying geography or configuration tables', function (): void {
    allowBusinessSearch($this->user);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $results = $this->search->getResults('Unmatched search value');

    expect($results->getCategories())->toBeEmpty();
    expect(implode('\n', $queries))->not->toContain('from `city_pin_codes`', 'from `cities`', 'from `countries`', 'from `approval_flows`');
    expect(Filament::getPanel('admin')->getGlobalSearchProvider())->toBeInstanceOf(BusinessSearchProvider::class);
});

it('ranks exact product codes first and limits each category to five records', function (): void {
    allowBusinessSearch($this->user);
    ItemMaster::factory()->create(['item_name' => 'Exact product'])->forceFill(['item_code' => 'MED-42'])->saveQuietly();
    ItemMaster::factory()->count(8)->create(['item_name' => 'MED-42 matching product']);

    $items = $this->search->getResults('MED-42')->getCategories()->get('Items');

    expect($items)->toHaveCount(5);
    expect($items->first()->title)->toBe('Exact product — MED-42');
});

it('does not search product descriptions or interpret wildcard characters as a broad query', function (): void {
    allowBusinessSearch($this->user);
    ItemMaster::factory()->create(['item_name' => 'Ordinary product', 'description' => 'HiddenRemarkNeedle']);

    expect($this->search->getResults('HiddenRemarkNeedle')->getCategories())->toBeEmpty();
    expect($this->search->getResults('%%')->getCategories())->toBeEmpty();
    expect($this->search->getResults('ItemMasterResource')->getCategories())->toBeEmpty();
});

it('excludes deleted products and records the user cannot open', function (): void {
    $item = ItemMaster::factory()->create(['item_name' => 'PermissionNeedle']);
    $this->user->givePermissionTo(Permission::findOrCreate('ViewAny:ItemMaster', 'web'));

    expect($this->search->getResults('PermissionNeedle')->getCategories())->toBeEmpty();

    $this->user->givePermissionTo(Permission::findOrCreate('View:ItemMaster', 'web'));
    expect($this->search->getResults('PermissionNeedle')->getCategories()->get('Items'))->toHaveCount(1);

    $item->delete();
    expect($this->search->getResults('PermissionNeedle')->getCategories())->toBeEmpty();
});

it('does not search for blank input or unauthenticated users', function (): void {
    expect($this->search->getResults('  ')->getCategories())->toBeEmpty();
    auth()->logout();
    expect($this->search->getResults('Search Rep')->getCategories())->toBeEmpty();
});

it('keeps account ownership restrictions when searching names and phone numbers', function (): void {
    $other = reportingUser('Other Rep');
    $owned = reportingDoctor($this->user, 'Owned SearchDoctor');
    reportingDoctor($other, 'Other SearchDoctor')->forceFill(['created_by' => $other->id])->saveQuietly();
    foreach (['ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster'] as $permission) {
        $this->user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    expect($this->user->can('ViewOwn:AccountMaster'))->toBeTrue();
    expect(AccountMasterResource::getEloquentQuery()->pluck('id')->all())->toBe([$owned->id]);
    expect(AccountMasterResource::getGlobalSearchEloquentQuery()->pluck('id')->all())->toBe([$owned->id]);

    $items = $this->search->getResults('SearchDoctor')->getCategories()->get('Accounts');

    expect($items)->toHaveCount(1);
    expect($items->first()->title)->toContain($owned->name);
});

it('matches a full contact name across name columns and searches mobile numbers', function (): void {
    allowBusinessSearch($this->user);
    ContactDetail::factory()->create(['salutation' => null, 'first_name' => 'Anjali', 'last_name' => 'Patel', 'mobile_number' => '9876543210']);

    $byName = $this->search->getResults('Anjali Patel')->getCategories()->get('Contacts');
    $byPhone = $this->search->getResults('9876543210')->getCategories()->get('Contacts');

    expect($byName)->toHaveCount(1);
    expect($byPhone)->toHaveCount(1);
    expect($byName->first()->title)->toBe('Anjali Patel');
    expect($byPhone->first()->details['Phone'])->toBe('9876543210');
});
