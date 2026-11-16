<?php

use App\Filament\Resources\SalesDcrExpenses\Pages\ListSalesDcrExpenses;
use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Models\Organization;
use App\Models\SalesDcr;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('denies the Admin list and direct expense requests when expenses are disabled', function () {
    $viewer = fieldReportingUser('ExpenseModuleViewer');
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
        ->and(SalesDcrExpenseResource::canViewAny())->toBeFalse();

    Livewire::test(ListSalesDcrExpenses::class)->assertForbidden();

    $dcr = SalesDcr::forceCreate(['user_id' => $viewer->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/dcrs/{$dcr->id}/expenses")->assertForbidden();
    $this->postJson("/api/v1/dcrs/{$dcr->id}/expenses", [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
});
