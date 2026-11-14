<?php

namespace App\Services\Approval;

use App\Enums\FieldPlanningMode;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Organization;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\OptionalModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnboardingApprovalService
{
    public function moduleOptions(): array
    {
        $enabled = app(OptionalModuleService::class)->capabilities()['enabled'];
        $planning = app(CompanyLaunchService::class)->proposal(app(CompanyOnboardingService::class)->organization());

        return collect(ApprovalSetting::eligibleModuleOptions())->mapWithKeys(fn (string $label, string $class): array => [class_basename($class) => $label])
            ->filter(fn (string $label, string $module): bool => match ($module) {
                'SalesDcr' => true,
                'SalesTourPlan', 'SalesTourPlanDetail' => $planning?->mode === FieldPlanningMode::FormalMtp,
                'LeaveApplication', 'LeaveAdjustment', 'LeaveEncashment', 'LeaveCancellation', 'HrChangeRequest' => $enabled['hr'],
                'PurchaseOrder', 'GoodsReceiptNote', 'InventoryAdjustment', 'InventoryTransfer', 'DeliveryChallan' => $enabled['inventory'],
                'Quote', 'SalesOrder', 'SalesInvoice' => $enabled['crm'],
                default => false,
            })->all();
    }

    public function defaults(): array
    {
        return ['required' => ''];
    }

    public function hasExistingConfiguration(): bool
    {
        return ApprovalFlow::query()->exists() || ApprovalSetting::enabledModules() !== [];
    }

    public function review(array $input): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $organization = $company->organization();
        $existing = ($organization?->metadata['company_setup']['approvals_applied'] ?? false) || $this->hasExistingConfiguration();
        $answer = $existing ? 'existing' : ($input['required'] ?? '');
        if (! $existing && $answer === 'existing') {
            $answer = '';
        }
        if (! in_array($answer, ['yes', 'no', 'existing'], true)) {
            throw ValidationException::withMessages(['approval' => 'Choose Yes or No.']);
        }

        $modules = match ($answer) {
            'yes' => $this->moduleOptions(),
            'existing' => ApprovalSetting::approvedModuleOptions(),
            default => [],
        };
        if (app(CompanyLaunchService::class)->proposal($organization)?->mode === FieldPlanningMode::FormalMtp && ! isset($modules['SalesTourPlan'])) {
            throw ValidationException::withMessages(['approval' => 'Formal monthly planning requires Sales Tour Plan approval. Choose Yes or change the planning method.']);
        }

        return ['required' => $answer, 'modules' => $modules];
    }

    public function saveDraft(array $input): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $organization = $company->organization();
        abort_unless($organization, 422, 'Save company information first.');

        return DB::transaction(function () use ($organization, $input): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $review = $this->review($input);
            if (! ($organization->metadata['company_setup']['approvals_applied'] ?? false)) {
                $metadata = $organization->metadata ?? [];
                $metadata['company_setup']['approvals_draft'] = ['required' => $review['required']];
                $organization->update(['metadata' => $metadata]);
            }

            return $review;
        });
    }

    public function apply(Organization $organization): void
    {
        app(CompanyOnboardingService::class)->authorize();
        DB::transaction(function () use ($organization): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $metadata = $organization->metadata ?? [];
            if ($metadata['company_setup']['approvals_applied'] ?? false) {
                return;
            }
            $review = $this->review($metadata['company_setup']['approvals_draft'] ?? []);
            $ids = [];
            if ($review['required'] !== 'existing') {
                $modules = array_keys($review['modules']);
                $settings = ApprovalSetting::query()->lockForUpdate()->first() ?? new ApprovalSetting;
                $settings->fill(['enabled_modules' => $modules])->save();
                if ($modules !== []) {
                    $flow = (new ApprovalFlow)->saveConfiguration([
                        'name' => 'Reporting manager approvals', 'modules' => $modules,
                        'route_type' => 'reporting_manager', 'condition_type' => 'unconditional', 'active' => true,
                    ]);
                    $ids[] = $flow->id;
                }
            }
            $metadata['company_setup']['approvals_applied'] = true;
            $metadata['company_setup']['approval_flow_ids'] = $ids;
            $organization->update(['metadata' => $metadata]);
        });
    }
}
