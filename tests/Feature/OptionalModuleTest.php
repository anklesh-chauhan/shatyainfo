<?php

use App\Filament\Pages\OptionalModules;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Models\ExpenseConfiguration;
use App\Models\ExpenseType;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use App\Services\OptionalModuleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(reportingUser('Module Admin'));
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->organization = Organization::query()->create(['name' => 'Module Pharma',
        'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->service = app(OptionalModuleService::class);
});

it('requires configuration and an explicit review before activation', function () {
    expect($this->service->status()['expenses']['ready'])->toBeFalse()
        ->and($this->service->navigationEnabled('SalesDcrExpense'))->toBeFalse()
        ->and(SalesDcrExpenseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(LeadResource::shouldRegisterNavigation())->toBeFalse();
    expect(fn () => $this->service->activate('expenses', true))->toThrow(ValidationException::class);
    ExpenseType::query()->create(['name' => 'Travel', 'code' => 'TRAVEL', 'is_active' => true]);
    expect($this->service->status()['expenses']['ready'])->toBeFalse();
    ExpenseConfiguration::query()->create(['expense_type_id' => ExpenseType::query()->sole()->id,
        'calculation_strategy' => 'flat', 'effective_from' => today(), 'is_active' => true]);
    expect(fn () => $this->service->activate('expenses', false))->toThrow(ValidationException::class);
    $this->service->activate('expenses', true);
    $first = $this->organization->fresh()->metadata;
    $this->service->activate('expenses', true);
    expect($this->organization->fresh()->metadata)->toBe($first)
        ->and($first['optional_modules']['expenses']['activated_by'])->toBe(auth()->id())
        ->and($this->service->navigationEnabled('SalesDcrExpense'))->toBeTrue()
        ->and(SalesDcrExpenseResource::shouldRegisterNavigation())->toBeTrue()
        ->and($this->service->navigationEnabled('Visit'))->toBeTrue()
        ->and($this->service->navigationEnabled('Lead'))->toBeFalse();
});

it('rejects unknown modules and unauthorized activation', function () {
    expect(fn () => $this->service->activate('unknown', true))->toThrow(ValidationException::class);
    app()->instance(CompanyOnboardingService::class, new CompanyOnboardingService);
    expect(fn () => $this->service->activate('expenses', true))
        ->toThrow(HttpException::class);
});

it('publishes activation flags and rejects future or expired expense rules', function () {
    $type = ExpenseType::query()->create(['name' => 'Travel', 'code' => 'TRAVEL', 'is_active' => true]);
    $rule = ExpenseConfiguration::query()->create(['expense_type_id' => $type->id,
        'calculation_strategy' => 'flat', 'effective_from' => today()->addDay(), 'is_active' => true]);
    expect($this->service->status()['expenses']['ready'])->toBeFalse()
        ->and($this->service->capabilities()['enabled']['expenses'])->toBeFalse();
    $rule->update(['effective_from' => today()->subDays(2), 'effective_to' => today()->subDay()]);
    expect($this->service->status()['expenses']['ready'])->toBeFalse();
    $rule->update(['effective_to' => null]);
    $this->service->activate('expenses', true);
    expect($this->service->capabilities()['enabled'])->toBe([
        'inventory' => false, 'expenses' => true, 'hr' => false, 'crm' => false,
    ]);
});

it('renders prerequisite progress and activates through the settings page', function () {
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99996, 'name' => 'Module Settings', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'web'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    try {
        ExpenseType::query()->create(['name' => 'Travel', 'code' => 'TRAVEL', 'is_active' => true]);
        ExpenseConfiguration::query()->create(['expense_type_id' => ExpenseType::query()->sole()->id,
            'calculation_strategy' => 'flat', 'effective_from' => today(), 'is_active' => true]);
        Livewire::test(OptionalModules::class)->assertSee('Samples and inventory')
            ->assertSee('Create at least one item')->assertSee('3 of 3 configuration checks complete')
            ->set('reviewed.expenses', true)->call('activate', 'expenses')
            ->assertHasNoErrors()->assertNotified();
        expect($this->organization->fresh()->metadata['optional_modules']['expenses']['enabled'])->toBeTrue();
    } finally {
        Tenant::forgetCurrent();
    }
});
