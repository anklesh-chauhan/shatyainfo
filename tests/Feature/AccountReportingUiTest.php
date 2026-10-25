<?php

use App\Filament\Pages\Reports\AccountReachFrequency;
use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Filament\Resources\AccountMasters\Pages\AccountMasterInfolist;
use App\Filament\Resources\AccountMasters\Pages\CreateAccountMaster;
use App\Filament\Resources\AccountMasters\Pages\EditAccountMaster;
use App\Filament\Resources\AccountMasters\RelationManagers\AssignmentHistoryRelationManager;
use App\Filament\Resources\AccountMasters\RelationManagers\CallTargetsRelationManager;
use App\Filament\Resources\AccountMasters\RelationManagers\DivisionProfilesRelationManager;
use App\Filament\Resources\AccountReportingMasters\Pages\ListAccountReportingMasters;
use App\Models\AccountReportingMaster;
use App\Services\AccountReportingService;
use Database\Seeders\AccountReportingMasterSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('renders the reporting screens and relation managers under account permissions', function () {
    $user = fieldSalesUser('Reporting Screens');
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:RatingType', 'web'));
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->seed(AccountReportingMasterSeeder::class);
    $account = reportingDoctor($user, 'UI Account');
    foreach (AccountMasterResource::getRelations() as $manager) {
        expect(class_exists($manager))->toBeTrue();
        expect((new ReflectionClass($manager))->getName())->toBe($manager);
    }
    Livewire::test(AccountMasterInfolist::class, ['record' => $account->getRouteKey()])->assertSuccessful();
    Livewire::test(CreateAccountMaster::class)->assertSuccessful()->assertFormFieldExists('specialty_id')->assertFormFieldExists('supplier_account_id');
    Livewire::test(ListAccountReportingMasters::class)->assertSuccessful();
    foreach ([DivisionProfilesRelationManager::class, CallTargetsRelationManager::class, AssignmentHistoryRelationManager::class] as $manager) {
        Livewire::test($manager, ['ownerRecord' => $account, 'pageClass' => EditAccountMaster::class])->assertSuccessful();
    }
    Livewire::test(AccountReachFrequency::class)->assertSuccessful()->assertCanSeeTableRecords([$account])->callAction('export')->assertFileDownloaded('account-reach-frequency-'.now()->format('Y-m').'.csv');
});

it('exposes only active reporting choices and rejects anonymous access', function () {
    $this->getJson('/api/v1/accounts/reporting-options')->assertUnauthorized();
    $user = fieldSalesUser('Reporting Choices');
    Sanctum::actingAs($user);
    $this->seed(AccountReportingMasterSeeder::class);
    $master = AccountReportingMaster::firstOrFail();
    $master->update(['is_active' => false]);
    $response = $this->getJson('/api/v1/accounts/reporting-options')->assertSuccessful();
    expect(collect($response->json('data.masters'))->pluck('id'))->not->toContain($master->id);
});

it('keeps other users accounts out of the reach report', function () {
    $user = fieldSalesUser('Report Visible');
    $this->actingAs($user);
    $visible = reportingDoctor($user, 'Visible Report Account');
    $this->actingAs(fieldSalesUser('Report Hidden'));
    $hidden = reportingDoctor(auth()->user(), 'Hidden Report Account');
    $this->actingAs($user);
    $ids = app(AccountReportingService::class)->monthlyQuery(now()->format('Y-m'))->pluck('id');
    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});
