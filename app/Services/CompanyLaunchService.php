<?php

namespace App\Services;

use App\Enums\FieldPlanningMode;
use App\Models\AccountMaster;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\FieldPlanningRevision;
use App\Models\FieldUserHq;
use App\Models\Organization;
use App\Models\Patch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class CompanyLaunchService
{
    /** @return array<string, array{label: string, description: string}> */
    public static function workflows(): array
    {
        return [
            'direct_dcr' => ['label' => 'Report visits directly', 'description' => 'Report customer visits without preparing a plan first.'],
            'flexible' => ['label' => 'Plan when useful', 'description' => 'Prepare optional daily or weekly intentions and report unplanned visits with a reason.'],
            'formal_mtp' => ['label' => 'Require monthly plans', 'description' => 'Use structured monthly tour plans approved before visits.'],
        ];
    }

    public function saveWorkflow(string $mode, int $expectedVersion): void
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        Validator::make(['workflow' => $mode], ['workflow' => ['required', Rule::in(array_keys(self::workflows()))]])->validate();
        $organization = $company->organization();
        abort_unless($organization && (Tenant::current()?->accountIsConfigured() ?? true), 422, 'Save company information first.');
        $organization->getConnection()->transaction(function () use ($organization, $mode, $expectedVersion): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $revision = app(FieldPlanningSettingsService::class)->saveProposal(auth()->user(), [
                'mode' => $mode,
                'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::from($mode)),
                'proposed_effective_on' => null,
                'reason' => 'Company setup: '.self::workflows()[$mode]['label'],
            ], $expectedVersion);
            $metadata = $organization->metadata ?? [];
            $metadata['company_setup']['planning_revision_id'] = $revision->id;
            $organization->update(['metadata' => $metadata]);
        });
    }

    public function proposal(?Organization $organization): ?FieldPlanningRevision
    {
        $id = $organization?->metadata['company_setup']['planning_revision_id'] ?? null;

        return $id ? FieldPlanningRevision::query()->find($id) : null;
    }

    public function requiresHq(?FieldPlanningRevision $revision = null): bool
    {
        $revision ??= $this->proposal(Organization::query()->first()) ?? app(FieldPlanningService::class)->active();

        return $revision?->mode !== FieldPlanningMode::DirectDcr;
    }

    public function requiresTerritoryLinkedHq(): bool
    {
        $revision = $this->proposal(Organization::query()->first()) ?? app(FieldPlanningService::class)->active();

        return $revision?->mode === FieldPlanningMode::FormalMtp;
    }

    /** @return array<string, mixed> */
    public function reviewPage(string $search, string $filter, array $selectedIds, int $page): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $launchedIds = array_keys($company->organization()?->metadata['company_setup']['launched_users'] ?? []);
        $search = trim(mb_substr($search, 0, 100));
        $employees = Employee::query()
            ->when($search !== '', function ($query) use ($search): void {
                foreach (preg_split('/\s+/', $search) as $term) {
                    $query->where(fn ($names) => $names->whereLike('first_name', '%'.$term.'%')
                        ->orWhereLike('middle_name', '%'.$term.'%')->orWhereLike('last_name', '%'.$term.'%')
                        ->orWhereLike('employee_id', '%'.$term.'%'));
                }
            })
            ->when($filter === 'selected', fn ($query) => $query->whereKey($selectedIds))
            ->when($filter === 'launched', fn ($query) => $query->whereIn('login_id', $launchedIds))
            ->when($filter === 'pending', fn ($query) => $query->where(fn ($pending) => $pending->whereNull('login_id')->orWhereNotIn('login_id', $launchedIds)));
        $total = $employees->count();
        $perPage = 25;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, $page));
        $ids = $employees->orderBy('first_name')->orderBy('id')->forPage($page, $perPage)->pluck('id')->all();

        return [
            ...$this->readiness($ids),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'first' => $ids === [] ? 0 : ($page - 1) * $perPage + 1,
            'last' => ($page - 1) * $perPage + count($ids),
        ];
    }

    /** @return array<string, mixed> */
    public function readiness(?array $employeeIds = null, bool $activeOnly = false): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $organization = $company->organization();
        $revision = $activeOnly ? app(FieldPlanningService::class)->active() : ($this->proposal($organization) ?? app(FieldPlanningService::class)->active());
        $blockers = [];
        if (! (Tenant::current()?->accountIsConfigured() ?? true)) {
            $blockers[] = 'Company preparation is still in progress.';
        }
        if (! ($organization?->metadata['company_setup']['organization_saved'] ?? false)) {
            $blockers[] = 'Save company information.';
        }
        if (! $revision) {
            $blockers[] = 'Choose and save your working method.';
        }
        if ($revision?->proposed_effective_on?->isFuture()) {
            $blockers[] = 'The chosen policy starts in the future. Launch on or after its start date.';
        }
        if ($revision?->mode === FieldPlanningMode::FormalMtp
            && ($organization?->metadata['company_setup']['approvals_applied'] ?? false)
            && ! ApprovalSetting::moduleRequiresApproval('SalesTourPlan')) {
            $blockers[] = 'Enable Sales Tour Plan approvals and configure monthly-plan approvers before launch.';
        }
        $employees = Employee::query()->with(['user.roles.permissions', 'user.permissions', 'positions.territories'])
            ->when($employeeIds !== null, fn ($query) => $query->whereKey($employeeIds))->orderBy('first_name')->orderBy('id')->get();
        $userIds = $employees->pluck('login_id')->filter()->all();
        $hqs = FieldUserHq::query()->with('location.territories')->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $accounts = AccountMaster::query()->whereIn('owner_id', $userIds)
            ->select('owner_id')->selectRaw('COUNT(*) AS customer_count')->groupBy('owner_id')->pluck('customer_count', 'owner_id');
        $launched = $organization?->metadata['company_setup']['launched_users'] ?? [];
        $rows = [];
        foreach ($employees as $employee) {
            $issues = [];
            $user = $employee->user;
            $territories = $employee->positions->flatMap->territories->pluck('id')->unique()->all();
            $hq = $hqs->get($employee->login_id);
            $customerCount = (int) $accounts->get($employee->login_id, 0);
            if (! $employee->is_active || $employee->is_deleted) {
                $issues['employee'] = 'Activate this employee.';
            }
            if (! $user || blank($user->email) || blank($user->password)) {
                $issues['login'] = 'Create and link login access.';
            } elseif (! $user->can('Create:Visit') || ! $user->can('ViewAny:AccountMaster') || ! $user->can('View:AccountMaster')) {
                $issues['permissions'] = 'Grant visit creation and customer list access.';
            }
            if ($this->requiresHq($revision) && ! $hq?->location?->is_active) {
                $issues['hq'] = 'Assign an active HQ.';
            } elseif ($revision?->mode === FieldPlanningMode::FormalMtp
                && $hq->location->territories->pluck('id')->intersect($territories)->isEmpty()) {
                $issues['hq'] = 'Assign an HQ linked to at least one territory of this MR’s positions. Manage HQ territory links in Location Masters.';
            }
            if ($customerCount === 0) {
                $issues['customers'] = 'Assign customers to this MR.';
            } elseif ($user && ! AccountMaster::query()->applyVisibility('AccountMaster', $user)->where('owner_id', $user->id)->exists()) {
                $issues['visibility'] = 'Grant visibility of the assigned customers.';
            }
            if (($revision?->rules['require_territory'] ?? false) && $territories === []) {
                $issues['territory'] = 'Assign a position with a territory for this workflow.';
            }
            if (($revision?->rules['require_patch'] ?? false) && ! Patch::query()->whereIn('territory_id', $territories)->exists()) {
                $issues['patch'] = 'Create a patch in the assigned territory.';
            }
            if ($user && ($organization?->metadata['company_setup']['approvals_applied'] ?? false)) {
                try {
                    app(ApprovalService::class)->validateRouteFor($user, 'SalesDcr', $user->territory_id ? (int) $user->territory_id : null);
                } catch (LogicException $exception) {
                    $issues['approvals'] = 'DCR approvals: '.$exception->getMessage();
                }
                if ($revision?->mode === FieldPlanningMode::FormalMtp) {
                    try {
                        foreach ($territories ?: [null] as $territoryId) {
                            app(ApprovalService::class)->validateRouteFor($user, 'SalesTourPlan', $territoryId);
                        }
                        if (count($territories) > 1) {
                            app(ApprovalService::class)->validateRouteFor($user, 'SalesTourPlan');
                        }
                    } catch (LogicException $exception) {
                        $issues['plan_approvals'] = 'Monthly plan approvals: '.$exception->getMessage();
                    }
                }
            }
            $rows[] = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_id, 'user_id' => $user?->id, 'name' => $employee->full_name,
                'hq' => $hq?->location?->name, 'customers' => $customerCount, 'issues' => array_values($issues), 'issue_details' => $issues,
                'ready' => $issues === [] && $blockers === [], 'launched' => isset($launched[$employee->login_id])];
        }

        return ['mode' => $revision?->mode->value, 'policy_revision' => $revision?->id, 'blockers' => $blockers,
            'members' => $rows, 'ready_count' => collect($rows)->where('ready', true)->count(),
            'launched_count' => count($launched), 'approval_check_pending' => ! ($organization?->metadata['company_setup']['approvals_applied'] ?? false)];
    }

    /** @param list<int|string> $employeeIds */
    public function launch(array $employeeIds, int $expectedRevisionId): void
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $data = Validator::make(['members' => $employeeIds], [
            'members' => ['required', 'array', 'min:1', 'max:5000'],
            'members.*' => ['required', 'integer', 'distinct', Rule::exists(Employee::class, 'id')->whereNull('deleted_at')],
        ])->validate();
        $organization = $company->organization();
        abort_unless($organization, 422, 'Save company information first.');
        $organization->getConnection()->transaction(function () use ($organization, $data, $expectedRevisionId): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $revision = $this->proposal($organization);
            if (! $revision || $revision->id !== $expectedRevisionId) {
                throw ValidationException::withMessages(['workflow' => 'The working method changed. Reload and review before launching.']);
            }
            if (app(FieldPlanningSettingsService::class)->latest(auth()->user())?->id !== $revision->id) {
                throw ValidationException::withMessages(['workflow' => 'A newer planning proposal exists. Review and save your working method again.']);
            }
            if ($revision->proposed_effective_on?->isFuture()) {
                throw ValidationException::withMessages(['workflow' => 'The chosen policy is not effective yet.']);
            }
            Employee::query()->whereKey($data['members'])->orderBy('id')->lockForUpdate()->get();
            if (app(FieldPlanningService::class)->active()?->id !== $revision->id) {
                app(FieldPlanningSettingsService::class)->activate(auth()->user(), $revision->id, $revision->version);
            }
            app(OnboardingApprovalService::class)->apply($organization);
            $review = $this->readiness($data['members'], activeOnly: true);
            $errors = $review['blockers'];
            foreach ($review['members'] as $member) {
                foreach ($member['issues'] as $issue) {
                    $errors[] = $member['name'].': '.$issue;
                }
            }
            if ($errors !== [] || count($review['members']) !== count($data['members'])) {
                throw ValidationException::withMessages(['launch' => $errors ?: ['Review the selected team again.']]);
            }
            $organization->refresh();
            $metadata = $organization->metadata ?? [];
            $newMembers = [];
            foreach ($review['members'] as $member) {
                if (! isset($metadata['company_setup']['launched_users'][$member['user_id']])) {
                    $newMembers[] = ['employee_id' => $member['employee_id'], 'user_id' => $member['user_id'],
                        'name' => $member['name'], 'hq' => $member['hq']];
                }
                $metadata['company_setup']['launched_users'][$member['user_id']] ??= [
                    'launched_at' => now()->toIso8601String(), 'launched_by' => auth()->id(), 'policy_revision_id' => $revision->id,
                ];
            }
            if ($newMembers !== []) {
                $metadata['company_setup']['launch_history'][] = [
                    'launched_at' => now()->toIso8601String(), 'launched_by' => auth()->id(),
                    'policy_revision_id' => $revision->id, 'policy_version' => $revision->version,
                    'mode' => $revision->mode->value, 'members' => $newMembers,
                ];
            }
            $metadata['company_setup']['completed'] = true;
            $organization->update(['metadata' => $metadata]);
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        $company = app(CompanyOnboardingService::class);
        $company->authorize();
        $history = $company->organization()?->metadata['company_setup']['launch_history'] ?? [];
        $actors = User::query()->whereIn('id', array_column($history, 'launched_by'))->pluck('name', 'id');

        return array_map(fn (array $event): array => [
            ...$event, 'actor_name' => $actors->get($event['launched_by'], 'Unavailable user'),
        ], array_reverse($history));
    }

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $organization = Organization::query()->first();
        $hq = FieldUserHq::query()->with('location')->where('user_id', $user->id)->first();
        $launched = $organization?->metadata['company_setup']['launched_users'][$user->id] ?? null;
        $employee = $user->employee;
        $managerId = $employee ? app(EmployeeReportingService::class)->resolve($employee)['manager'] : null;
        $manager = $managerId ? Employee::query()->where('is_active', true)->find($managerId) : null;
        if ($manager?->is_deleted) {
            $manager = null;
        }
        $contact = $manager?->full_name ?: ($organization?->contact_person ?: 'your company administrator');

        return ['schema_version' => 1, 'company_name' => $organization?->name ?? Tenant::current()?->name,
            'hq_name' => $hq?->location?->name, 'launched' => $launched !== null,
            'assigned_customers' => AccountMaster::query()->applyVisibility('AccountMaster', $user)->where('owner_id', $user->id)->count(),
            'contact' => $contact,
            'contact_kind' => $manager ? 'manager' : 'administrator',
            'contact_email' => $manager ? $manager->email : $organization?->contact_person_email,
            'contact_phone' => $manager ? $manager->mobile_number : $organization?->contact_person_phone,
            'message' => $launched ? 'Your team has launched. Prepare your data online before working offline.' : 'Your company administrator is preparing your team. Your saved work remains available.'];
    }
}
