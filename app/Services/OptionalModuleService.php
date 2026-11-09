<?php

namespace App\Services;

use App\Models\AccountMaster;
use App\Models\ExpenseConfiguration;
use App\Models\ExpenseType;
use App\Models\ItemMaster;
use App\Models\LeaveRule;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\ShiftMaster;
use Illuminate\Validation\ValidationException;

class OptionalModuleService
{
    public const MODULES = [
        'inventory' => 'Samples and inventory',
        'expenses' => 'Expenses',
        'hr' => 'Attendance and leave',
        'crm' => 'Sales CRM',
    ];

    public const RESOURCES = [
        'inventory' => ['InventoryStock', 'InventoryMovement', 'InventoryTransfer', 'InventoryAdjustment', 'SampleRequest', 'SampleIssue', 'SgipDistribution'],
        'expenses' => ['SalesDcrExpense'],
        'hr' => ['DailyAttendance', 'EmployeeAttendance', 'LeaveApplication', 'LeaveCancellation', 'LeaveBalance', 'LeaveAdjustment'],
        'crm' => ['Lead', 'LeadActivity', 'Deal', 'Quote', 'SalesOrder', 'SalesInvoice'],
    ];

    public function navigationEnabled(string $resource): bool
    {
        foreach (self::RESOURCES as $module => $resources) {
            if (in_array($resource, $resources, true)) {
                $organization = Organization::query()->orderBy('id')->first();
                if (! ($organization?->metadata['company_setup']['organization_saved'] ?? false)) {
                    return true;
                }

                return (bool) ($organization->metadata['optional_modules'][$module]['enabled'] ?? false);
            }
        }

        return true;
    }

    public function status(): array
    {
        $organization = Organization::query()->orderBy('id')->first();
        $checks = [
            'inventory' => [
                'Create at least one item in Item Masters' => ItemMaster::query()->exists(),
                'Configure an active stock location' => LocationMaster::query()->where('is_active', true)->exists(),
            ],
            'expenses' => [
                'Create an active expense type' => ExpenseType::query()->where('is_active', true)->exists(),
                'Configure a currently effective expense rule' => ExpenseConfiguration::query()->where('is_active', true)
                    ->whereHas('expenseType', fn ($query) => $query->where('is_active', true))
                    ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))->exists(),
            ],
            'hr' => [
                'Configure at least one shift' => ShiftMaster::query()->exists(),
                'Configure an active leave rule' => LeaveRule::query()->where('is_active', true)->exists(),
            ],
            'crm' => [
                'Add at least one customer account' => AccountMaster::query()->exists(),
                'Create at least one item for quotes and orders' => ItemMaster::query()->exists(),
            ],
        ];
        $result = [];
        foreach (self::MODULES as $key => $label) {
            $requirements = [
                'Save company information' => (bool) ($organization?->metadata['company_setup']['organization_saved'] ?? false),
                ...$checks[$key],
            ];
            $activation = $organization?->metadata['optional_modules'][$key] ?? [];
            $result[$key] = ['label' => $label, 'enabled' => (bool) ($activation['enabled'] ?? false),
                'checks' => $requirements, 'ready' => ! in_array(false, $requirements, true),
                'activation' => $activation];
        }

        return $result;
    }

    public function capabilities(): array
    {
        $organization = Organization::query()->orderBy('id')->first();
        $configured = (bool) ($organization?->metadata['company_setup']['organization_saved'] ?? false);
        $enabled = [];
        foreach (self::MODULES as $key => $label) {
            $enabled[$key] = ! $configured || (bool) ($organization->metadata['optional_modules'][$key]['enabled'] ?? false);
        }

        return ['schema_version' => 1, 'enabled' => $enabled];
    }

    public function activate(string $module, bool $reviewed): void
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        if (! array_key_exists($module, self::MODULES)) {
            throw ValidationException::withMessages(['module' => 'Choose a supported module.']);
        }
        $organization = $company->organization();
        abort_unless($organization, 422, 'Save company information first.');
        $organization->getConnection()->transaction(function () use ($organization, $module, $reviewed): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($organization->metadata['optional_modules'][$module]['enabled'] ?? false) {
                return;
            }
            if (! $reviewed || ! $this->status()[$module]['ready']) {
                throw ValidationException::withMessages(['module' => 'Complete the prerequisites and confirm your permission and approval review.']);
            }
            $metadata = $organization->metadata ?? [];
            $metadata['optional_modules'][$module] = [
                'enabled' => true, 'activated_by' => auth()->id(),
                'activated_at' => now()->toIso8601String(), 'permissions_and_approvals_reviewed' => true,
            ];
            $organization->update(['metadata' => $metadata]);
        }, 3);
    }
}
