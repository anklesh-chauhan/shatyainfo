<?php

namespace App\Services\Approval;

use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Territory;
use App\Services\CompanyOnboardingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class OnboardingApprovalService
{
    public function moduleOptions(): array
    {
        return collect(ApprovalSetting::eligibleModuleOptions())->mapWithKeys(fn ($label, $class) => [class_basename($class) => $label])->all();
    }

    public function groups(): array
    {
        $groups = [];
        foreach ($this->moduleOptions() as $module => $label) {
            $group = match ($module) {
                'LeaveApplication', 'LeaveCancellation', 'LeaveAdjustment', 'LeaveEncashment', 'HrChangeRequest' => 'HR',
                'PurchaseOrder', 'GoodsReceiptNote' => 'Purchases',
                'InventoryAdjustment', 'InventoryTransfer', 'DeliveryChallan' => 'Inventory',
                'SalesDcr' => 'Expenses',
                default => 'Sales',
            };
            $groups[$group][$module] = $label;
        }

        return $groups;
    }

    public function defaults(): array
    {
        return ['required' => '', 'modules' => [], 'common' => ['levels' => 1, 'territory_id' => '', 'l1_role_id' => '', 'l1_scope' => 'all', 'l2_role_id' => '', 'l2_scope' => 'all'], 'overrides' => []];
    }

    public function hasExistingConfiguration(): bool
    {
        return ApprovalFlow::query()->exists() || ApprovalSetting::enabledModules() !== [];
    }

    public function review(array $input): array
    {
        app(CompanyOnboardingService::class)->authorize();
        $data = Validator::make($input, [
            'required' => ['required', Rule::in(['yes', 'no', 'existing'])],
        ])->validate();
        if ($data['required'] === 'existing') {
            if (! $this->hasExistingConfiguration()) {
                throw ValidationException::withMessages(['approval' => 'There is no existing configuration to retain.']);
            }

            return ['required' => 'existing', 'flows' => []];
        }
        if ($this->hasExistingConfiguration()) {
            throw ValidationException::withMessages(['approval' => 'Approval configuration already exists. Keep it here and make changes in Approval Settings and Approval Flows.']);
        }
        if ($data['required'] === 'no') {
            return ['required' => 'no', 'flows' => []];
        }
        $data = Validator::make($input, [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->moduleOptions()))],
            'common' => ['required', 'array'],
            'overrides' => ['sometimes', 'array'],
        ])->validate();
        $flows = [];
        foreach ($data['modules'] as $module) {
            $override = $data['overrides'][$module] ?? [];
            $config = ! empty($override['custom']) ? $override : $data['common'];
            foreach (['territory_id', 'l1_role_id', 'l2_role_id'] as $key) {
                if (($config[$key] ?? '') === '') {
                    $config[$key] = null;
                }
            }
            $config = Validator::make($config, [
                'levels' => ['required', 'integer', Rule::in([1, 2])],
                'territory_id' => ['nullable', 'integer', Rule::exists(Territory::class, 'id')->whereNull('deleted_at')],
                'l1_role_id' => ['required', 'integer', Rule::exists(JobRole::class, 'id')->whereNull('deleted_at')],
                'l1_scope' => ['required', Rule::in(['all', 'self', 'children'])],
                'l2_role_id' => ['required_if:levels,2', 'nullable', 'integer', Rule::exists(JobRole::class, 'id')->whereNull('deleted_at')],
                'l2_scope' => ['required_if:levels,2', 'nullable', Rule::in(['all', 'self', 'children'])],
            ])->validate();
            $territoryId = empty($config['territory_id']) ? null : (int) $config['territory_id'];
            $steps = [];
            $users = [];
            for ($level = 1; $level <= (int) $config['levels']; $level++) {
                $roleId = (int) $config['l'.$level.'_role_id'];
                $scope = $config['l'.$level.'_scope'];
                try {
                    $user = app(ApprovalService::class)->previewApprover($roleId, $territoryId, $scope, $module);
                } catch (LogicException $exception) {
                    throw ValidationException::withMessages(['approval' => $this->moduleOptions()[$module].' L'.$level.': '.$exception->getMessage()]);
                }
                if (in_array($user->id, $users, true)) {
                    throw ValidationException::withMessages(['approval' => 'L1 and L2 must resolve to different approvers for '.$this->moduleOptions()[$module].'.']);
                }
                $users[] = $user->id;
                $steps[] = ['job_role_id' => $roleId, 'territory_scope' => $scope, 'role' => JobRole::findOrFail($roleId)->name, 'approver' => $user->name];
            }
            $flows[] = ['module' => $module, 'label' => $this->moduleOptions()[$module], 'territory_id' => $territoryId, 'territory' => $territoryId ? Territory::findOrFail($territoryId)->name : 'All territories', 'steps' => $steps];
        }

        return ['required' => 'yes', 'flows' => $flows];
    }

    public function saveDraft(array $input): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $organization = $company->organization();
        abort_unless($organization, 422, 'Save company information first.');

        return DB::transaction(function () use ($organization, $input): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $metadata = $organization->metadata ?? [];
            if ($metadata['company_setup']['approvals_applied'] ?? false) {
                return ['required' => 'existing', 'flows' => []];
            }
            $review = $this->review($input);
            $metadata['company_setup']['approvals_draft'] = $input;
            $organization->update(['metadata' => $metadata]);

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
                $settings = ApprovalSetting::query()->lockForUpdate()->first() ?? new ApprovalSetting;
                $settings->fill(['enabled_modules' => array_column($review['flows'], 'module')])->save();
                foreach ($review['flows'] as $row) {
                    $flow = ApprovalFlow::create(['module' => $row['module'], 'active' => false, 'condition_type' => 'amount', 'version' => 1, 'priority' => 0, 'territory_id' => $row['territory_id']]);
                    foreach ($row['steps'] as $index => $step) {
                        $flow->steps()->create(['step_order' => $index + 1, 'job_role_id' => $step['job_role_id'], 'territory_scope' => $step['territory_scope'], 'can_skip' => false, 'sla_hours' => 24]);
                    }
                    $flow->update(['active' => true]);
                    cache()->forget('approval:module:'.$row['module']);
                    $ids[] = $flow->id;
                }
            }
            $metadata['company_setup']['approvals_applied'] = true;
            $metadata['company_setup']['approval_flow_ids'] = $ids;
            $organization->update(['metadata' => $metadata]);
        });
    }
}
