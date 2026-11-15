<?php

use App\Filament\Resources\SalesDcrExpenses\Pages\ListSalesDcrExpenses;
use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('hides DCR Expenses from the menu and still opens the list when expenses are disabled', function () {
    $viewer = reportingUser('ExpenseModuleViewer');
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:SalesDcrExpense', 'web'));
    Organization::query()->create([
        'name' => 'Expense Module Pharma',
        'metadata' => [
            'company_setup' => ['organization_saved' => true],
            'optional_modules' => ['expenses' => ['enabled' => false]],
        ],
    ]);

    expect($viewer->can('AccessAllRecords'))->toBeFalse()
        ->and($viewer->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse();

    $this->actingAs($viewer, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(SalesDcrExpenseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SalesDcrExpenseResource::canViewAny())->toBeTrue();

    Livewire::test(ListSalesDcrExpenses::class)->assertSuccessful();
});
