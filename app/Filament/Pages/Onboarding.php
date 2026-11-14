<?php

namespace App\Filament\Pages;

use App\Enums\FieldPlanningMode;
use App\Filament\Clusters\GlobalConfiguration\OrganizationAndCompanyCluster;
use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Patches\PatchResource;
use App\Filament\Resources\Users\UserResource;
use App\Jobs\ProcessAccountImport;
use App\Jobs\SeedTenantMasters;
use App\Models\Employee;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountOnboardingService;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\AccountImportTemplate;
use App\Services\DataImport\ImportDownloadResponse;
use App\Services\DataImport\ImportRowException;
use App\Services\DataImport\TerritoryImportTemplate;
use App\Services\DateFormatService;
use App\Services\Field\FieldPlanningSettingsService;
use App\Services\Field\TeamAssignmentService;
use App\Services\TerritoryOnboardingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Onboarding extends Page
{
    protected static ?string $cluster = OrganizationAndCompanyCluster::class;

    use WithFileUploads;

    public mixed $companyLogo = null;

    #[Locked]
    public ?string $importSection = null;

    public function getView(): string
    {
        return $this->importSection === null ? parent::getView() : 'filament.pages.partials.onboarding-core-import';
    }

    public function updatedCompanyLogo(): void
    {
        $this->validateOnly('companyLogo', [
            'companyLogo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
        ]);
    }

    public function companyLogoUrl(): ?string
    {
        if ($this->companyLogo && ! $this->getErrorBag()->has('companyLogo')) {
            return $this->companyLogo->temporaryUrl();
        }

        $logo = app(CompanyOnboardingService::class)->organization()?->logo;

        return $logo ? Storage::disk('public')->url($logo) : null;
    }

    public mixed $accountFile = null;

    #[Locked]
    public array $accountPreview = [];

    #[Locked]
    public ?string $accountPreviewToken = null;

    #[Locked]
    public int $accountPreviewPage = 1;

    #[Locked]
    public array $accountImportResult = [];

    #[Locked]
    public array $accountImportStatus = [];

    public function refreshAccountImport(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->accountImportStatus = Cache::store('file')->get(ProcessAccountImport::statusKey((int) auth()->id(), Tenant::current()?->id), []);
        if (($this->accountImportStatus['status'] ?? null) === 'complete') {
            $this->accountImportResult = $this->accountImportStatus['result'];
        }
    }

    private function ensureAccountImportIdle(): void
    {
        $this->refreshAccountImport();
        if (in_array($this->accountImportStatus['status'] ?? null, ['queued', 'running'], true)) {
            throw ValidationException::withMessages(['accountFile' => 'Your account import is still in progress. Wait for it to complete before starting another.']);
        }
    }

    public function updatedAccountFile(): void
    {
        $this->forgetAccountPreview();
        $this->accountPreview = [];
        $this->accountImportResult = [];
        $this->resetErrorBag();
    }

    public function downloadAccountTemplate(): StreamedResponse
    {
        app(AccountOnboardingService::class)->authorize();

        return ImportDownloadResponse::from(response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'account-template-');
            try {
                app(AccountImportTemplate::class)->write($path);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, 'accounts-import.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
    }

    public function previewAccounts(): void
    {
        $this->ensureAccountImportIdle();
        $service = app(AccountOnboardingService::class);
        $service->authorize();
        $this->forgetAccountPreview();
        $this->accountPreview = [];
        $this->accountImportResult = [];
        $this->validate(['accountFile' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048']]);
        $preview = $service->preview($service->readFile($this->accountFile->getRealPath(), strtolower($this->accountFile->getClientOriginalExtension()) === 'xlsx'));
        $this->accountPreviewToken = (string) Str::uuid();
        Cache::put($this->accountPreviewCacheKey(), $preview, now()->addHour());
        $this->showAccountPreviewPage(1);
    }

    public function showAccountPreviewPage(int $page): void
    {
        app(AccountOnboardingService::class)->authorize();
        $preview = $this->storedAccountPreview();
        $pages = max(1, (int) ceil(count($preview['plans']) / 25));
        $this->accountPreviewPage = max(1, min($page, $pages));
        $this->accountPreview = [
            'row_count' => count($preview['rows']), 'created' => $preview['created'], 'updated' => $preview['updated'],
            'error_count' => count($preview['errors']), 'errors' => array_slice($preview['errors'], 0, 25),
            'plans' => array_slice($preview['plans'], ($this->accountPreviewPage - 1) * 25, 25), 'pages' => $pages,
        ];
    }

    private function accountPreviewCacheKey(): string
    {
        return 'account-import-preview:'.(Tenant::current()?->getKey() ?? 'central').':'.auth()->id().':'.$this->accountPreviewToken;
    }

    private function storedAccountPreview(): array
    {
        $preview = $this->accountPreviewToken ? Cache::get($this->accountPreviewCacheKey()) : null;
        if (! is_array($preview)) {
            throw ValidationException::withMessages(['accountFile' => 'The account preview has expired. Preview your file again.']);
        }

        return $preview;
    }

    private function forgetAccountPreview(): void
    {
        if ($this->accountPreviewToken !== null) {
            Cache::forget($this->accountPreviewCacheKey());
        }
        $this->accountPreviewToken = null;
        $this->accountPreviewPage = 1;
    }

    public function importAccounts(): void
    {
        app(AccountOnboardingService::class)->authorize();
        $key = ProcessAccountImport::statusKey((int) auth()->id(), Tenant::current()?->id);
        Cache::store('file')->lock($key.':dispatch', 30)->block(5, function () use ($key): void {
            $this->refreshAccountImport();
            if (in_array($this->accountImportStatus['status'] ?? null, ['queued', 'running'], true)) {
                return;
            }
            $preview = $this->storedAccountPreview();
            abort_unless($preview['errors'] === [], 422, 'Preview a valid file first.');
            $job = new ProcessAccountImport((string) Str::uuid(), (int) auth()->id(), Tenant::current()?->id);
            try {
                if (! Storage::disk('local')->put($job->payloadPath(), json_encode($preview['rows'], JSON_THROW_ON_ERROR))) {
                    throw new \RuntimeException('The account import file could not be stored.');
                }
                Cache::store('file')->put($key, ['token' => $job->token, 'status' => 'queued', 'message' => 'Waiting for the import worker', 'processed' => 0, 'total' => count($preview['rows'])], now()->addDays(7));
                dispatch($job);
            } catch (\Throwable $exception) {
                Cache::store('file')->forget($key);
                Storage::disk('local')->delete($job->payloadPath());
                report($exception);
                throw ValidationException::withMessages(['accountFile' => 'The import could not be queued. Please try again.']);
            }
            $this->forgetAccountPreview();
            $this->accountPreview = [];
            $this->accountImportResult = [];
            $this->reset('accountFile');
            $this->refreshAccountImport();
        });
    }

    public function downloadAccountErrors(): StreamedResponse
    {
        app(AccountOnboardingService::class)->authorize();

        $errors = $this->storedAccountPreview()['errors'];
        $this->skipRender();

        return ImportDownloadResponse::from(response()->streamDownload(function () use ($errors): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Row', 'External reference', 'Error'], ',', '"', '');
            foreach ($errors as $error) {
                $cells = [$error['row'], $error['reference'], $error['message']];
                fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r\n]/', $value) ? "'".$value : $value, $cells), ',', '"', '');
            }
            fclose($stream);
        }, 'account-import-errors.csv', ['Content-Type' => 'text/csv']));
    }

    public function usesDivisions(): bool
    {
        return Organization::divisionsEnabled();
    }

    public mixed $territoryFile = null;

    #[Locked]
    public array $territoryPreview = [];

    public function updatedTerritoryFile(): void
    {
        $this->territoryPreview = [];
    }

    public function downloadTerritoryTemplate(): StreamedResponse
    {
        app(CompanyOnboardingService::class)->authorize();

        return ImportDownloadResponse::from(response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'territory-template-');
            try {
                app(TerritoryImportTemplate::class)->write($path);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, 'territories-import.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
    }

    public function previewTerritories(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->territoryPreview = [];
        $this->validate(['territoryFile' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048']]);
        $service = app(TerritoryOnboardingService::class);
        $this->territoryPreview = $service->preview($service->readFile($this->territoryFile->getRealPath(), strtolower($this->territoryFile->getClientOriginalExtension()) === 'xlsx'));
    }

    public function importTerritories(): void
    {
        $count = app(TerritoryOnboardingService::class)->import($this->territoryPreview);
        $this->territoryPreview = [];
        $this->reset('territoryFile');
        Notification::make()->title($count.' territories created. Supplied HQ links saved; existing links preserved.')->success()->send();
    }

    public function skipTerritories(): void
    {
        if ($this->requiresMonthlyPlans()) {
            $this->reviewTerritories();

            return;
        }
        app(CompanyOnboardingService::class)->mark('territories_reviewed');
        $this->resetErrorBag();
        $this->step = 3;
    }

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Setup Checklist';

    protected static ?string $title = 'Setup Checklist';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.onboarding';

    public array $organization = [];

    #[Locked]
    public bool $draftSaved = false;

    public function saveDraft(bool $deferred = false): void
    {
        app(CompanyOnboardingService::class)->saveDraft([
            'step' => $this->step,
            'organization' => array_map(fn ($value): mixed => is_scalar($value) || $value === null ? (string) $value : $value, $this->organization),
            'workflow' => $this->workflow,
            'workflow_version' => $this->workflowVersion,
            'approval' => $this->approval,
            'deferred' => $deferred,
        ]);
        $this->draftSaved = true;
    }

    public function updatedOrganization(): void
    {
        $this->saveDraft();
    }

    public function updatedWorkflow(): void
    {
        $this->saveDraft();
    }

    public string $workflow = '';

    public function requiresMonthlyPlans(): bool
    {
        return app(CompanyLaunchService::class)->proposal(app(CompanyOnboardingService::class)->organization())?->mode === FieldPlanningMode::FormalMtp;
    }

    /** @return array<int, string> */
    public function setupSteps(): array
    {
        return [
            1 => 'Company information',
            2 => 'Working method',
        ] + ($this->requiresMonthlyPlans() ? [7 => 'Territories and HQs'] : [])
            + [3 => $this->workflow === 'direct_dcr' ? 'Team' : 'Team and HQs', 4 => 'Customers', 5 => 'Reporting rules', 6 => 'Review and launch'];
    }

    public function reviewTerritories(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->resetErrorBag();
        if ($this->requiresMonthlyPlans() && (! $service->checklist()['territory_count'] || ! LocationMaster::query()->where('is_active', true)->has('territories')->exists())) {
            $this->addError('territories', 'Create territories and link at least one active HQ to a territory before adding your monthly-plan team.');

            return;
        }
        $service->mark('territories_reviewed');
        $this->step = 3;
        $this->saveDraft();
    }

    public array $launchMembers = [];

    public string $launchSearch = '';

    public string $launchFilter = 'all';

    #[Locked]
    public int $launchPage = 1;

    public function updatedLaunchSearch(): void
    {
        $this->launchPage = 1;
    }

    public function updatedLaunchFilter(): void
    {
        $this->launchPage = 1;
    }

    public function goToLaunchPage(int $page): void
    {
        $this->launchPage = max(1, $page);
    }

    public function clearLaunchSelection(): void
    {
        $this->launchMembers = [];
        $this->updatedLaunchMembers();
    }

    public ?int $bulkHqId = null;

    public bool $replaceExistingHqs = false;

    #[Locked]
    public array $hqAssignmentPreview = [];

    public mixed $mappingFile = null;

    public bool $allowCustomerTransfers = false;

    #[Locked]
    public array $mappingRows = [];

    #[Locked]
    public array $mappingPreview = [];

    #[Locked]
    public array $territoryMappingPreview = [];

    public function updatedLaunchMembers(): void
    {
        $this->hqAssignmentPreview = [];
        $this->territoryMappingPreview = [];
    }

    public function updatedBulkHqId(): void
    {
        $this->hqAssignmentPreview = [];
    }

    public function updatedReplaceExistingHqs(): void
    {
        $this->hqAssignmentPreview = [];
    }

    public function updatedMappingFile(): void
    {
        $this->mappingRows = [];
        $this->mappingPreview = [];
    }

    public function updatedAllowCustomerTransfers(): void
    {
        $this->mappingPreview = [];
        $this->territoryMappingPreview = [];
    }

    public function selectReadyMembers(): void
    {
        $this->launchMembers = collect(app(CompanyLaunchService::class)->readiness()['members'])
            ->where('ready', true)->where('launched', false)->pluck('employee_id')->all();
        $this->updatedLaunchMembers();
    }

    public function previewTerritoryCustomerMappings(): void
    {
        $this->resetErrorBag();
        $this->territoryMappingPreview = [];
        $this->territoryMappingPreview = app(TeamAssignmentService::class)->previewTerritoryMappings($this->launchMembers, $this->allowCustomerTransfers);
    }

    public function confirmTerritoryCustomerMappings(): void
    {
        $count = app(TeamAssignmentService::class)->assignTerritoryMappings(
            $this->launchMembers,
            $this->allowCustomerTransfers,
            array_column($this->territoryMappingPreview['plans'] ?? [], 'account_id'),
            $this->territoryMappingPreview['fingerprint'] ?? '',
        );
        $this->territoryMappingPreview = [];
        $this->mappingPreview = [];
        Notification::make()->title($count.' customer assignments saved')->success()->send();
    }

    public function previewBulkHqs(): void
    {
        $this->resetErrorBag();
        $this->hqAssignmentPreview = [];
        $this->hqAssignmentPreview = app(TeamAssignmentService::class)->previewHqs($this->launchMembers, $this->bulkHqId ?? 0, $this->replaceExistingHqs);
    }

    public function confirmBulkHqs(): void
    {
        $count = app(TeamAssignmentService::class)->assignHqs($this->launchMembers, $this->bulkHqId ?? 0, $this->replaceExistingHqs, $this->hqAssignmentPreview['fingerprint'] ?? '');
        $this->hqAssignmentPreview = [];
        Notification::make()->title($count.' HQ assignments saved')->success()->send();
    }

    public function downloadCustomerMappings(): StreamedResponse
    {
        app(TeamAssignmentService::class)->authorize();

        return ImportDownloadResponse::from(response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'customer-mappings-');
            try {
                app(TeamAssignmentService::class)->writeMappingTemplate($path);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, 'customer-mappings.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
    }

    public function previewCustomerMappings(): void
    {
        $service = app(TeamAssignmentService::class);
        $service->authorize();
        $this->resetErrorBag();
        $this->mappingPreview = [];
        $this->mappingRows = [];
        $this->validate(['mappingFile' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048']]);
        try {
            $this->mappingRows = $service->readMappings($this->mappingFile->getRealPath(), strtolower($this->mappingFile->getClientOriginalExtension()) === 'xlsx');
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['mappingFile' => $exception->getMessage()]);
        }
        $this->mappingPreview = $service->previewMappings($this->mappingRows, $this->allowCustomerTransfers);
    }

    public function confirmCustomerMappings(): void
    {
        $count = app(TeamAssignmentService::class)->importMappings($this->mappingRows, $this->allowCustomerTransfers, $this->mappingPreview['fingerprint'] ?? '');
        $this->mappingRows = [];
        $this->mappingPreview = [];
        $this->mappingFile = null;
        $this->territoryMappingPreview = [];
        Notification::make()->title($count.' customer assignments saved')->success()->send();
    }

    #[Locked]
    public int $workflowVersion = 0;

    #[Locked]
    public int $workflowRevisionId = 0;

    public function saveWorkflow(): void
    {
        app(CompanyLaunchService::class)->saveWorkflow($this->workflow, $this->workflowVersion);
        $revision = app(FieldPlanningSettingsService::class)->latest(auth()->user());
        $this->workflowVersion = $revision->version;
        $this->workflowRevisionId = $revision->id;
        $this->step = $this->requiresMonthlyPlans() ? 7 : 3;
        $this->saveDraft();
        Notification::make()->title('Working method saved for launch')->success()->send();
    }

    public function launchReadiness(): array
    {
        return app(CompanyLaunchService::class)->reviewPage($this->launchSearch, $this->launchFilter, $this->launchMembers, $this->launchPage);
    }

    public function launchHistory(): array
    {
        return app(CompanyLaunchService::class)->history();
    }

    /** @return array{label: string, url: string}|null */
    public function readinessAction(string $issue, array $member): ?array
    {
        app(CompanyOnboardingService::class)->authorize();
        if (in_array($issue, ['hq', 'customers'], true) && $member['user_id'] && FieldTeamOnboarding::canAccess()) {
            return ['label' => app(CompanyLaunchService::class)->requiresHq() ? 'Assign HQ and customers' : 'Assign customers', 'url' => FieldTeamOnboarding::getUrl(['representative' => $member['user_id']], panel: 'admin')];
        }
        $resource = match ($issue) {
            'employee', 'login', 'territory' => EmployeeResource::class,
            'permissions', 'visibility' => UserResource::class,
            'patch' => PatchResource::class,
            'approvals', 'plan_approvals' => ApprovalFlowResource::class,
            default => null,
        };
        if (! $resource || ! $resource::canViewAny()) {
            return null;
        }
        $record = match ($issue) {
            'employee', 'login', 'territory' => Employee::query()->find($member['employee_id']),
            'permissions', 'visibility' => User::query()->find($member['user_id']),
            default => null,
        };

        return ['label' => match ($issue) {
            'employee', 'login' => 'Review employee and login',
            'territory' => 'Review employee positions',
            'permissions', 'visibility' => 'Review user access',
            'patch' => 'Manage patches',
            default => 'Review approval flows',
        }, 'url' => $record && $resource::canEdit($record)
            ? $resource::getUrl('edit', ['record' => $record], panel: 'admin')
            : $resource::getUrl('index', panel: 'admin')];
    }

    public array $approval = [];

    #[Locked]
    public array $approvalReview = [];

    public function approvalSetup(): OnboardingApprovalService
    {
        return app(OnboardingApprovalService::class);
    }

    public function updatedApproval(mixed $value, ?string $key): void
    {
        if ($key !== null && preg_match('/^(common|overrides\.[^.]+)\.(territory_id|l[12]_(role_id|scope))$/', $key, $matches)) {
            $section = $matches[1];
            foreach (($matches[2] === 'territory_id' ? [1, 2] : [(int) $matches[2][1]]) as $level) {
                data_set($this->approval, $section.'.l'.$level.'_user_id', '');
            }
        }
        if ($key !== null && str_starts_with($key, 'overrides.') && str_ends_with($key, '.custom') && $value) {
            $module = explode('.', $key)[1];
            if (array_key_exists($module, $this->approvalSetup()->moduleOptions())) {
                $this->approval['overrides'][$module] = array_merge($this->approval['common'], ['custom' => true]);
            }
        }
        $this->saveDraft();
    }

    public function reviewApprovals(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->approvalReview = $this->approvalSetup()->saveDraft($this->approval);
        $this->step = 6;
        $this->saveDraft();
    }

    public function approvalAlreadyApplied(): bool
    {
        return (bool) (app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['approvals_applied'] ?? false);
    }

    #[Locked]
    public int $step = 0;

    public function getLayout(): string
    {
        return 'filament-panels::components.layout.simple';
    }

    protected function getLayoutData(): array
    {
        return ['hasTopbar' => false, 'maxContentWidth' => Width::FiveExtraLarge];
    }

    public static function canAccess(): bool
    {
        return Tenant::checkCurrent() && (auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) ?? false);
    }

    public function mount(?string $importSection = null): void
    {
        abort_unless($importSection === null || in_array($importSection, ['territories', 'accounts'], true), 422);
        $this->importSection = $importSection;
        if (Tenant::current()?->accountIsConfigured()) {
            $this->refreshAccountImport();
        }
        if ($importSection !== null) {
            app(CompanyOnboardingService::class)->authorize();
            abort_unless(Tenant::current()->accountIsConfigured(), 409);

            return;
        }
        $tenant = Tenant::current();
        $this->ensureMasterSetupQueued($tenant);
        if ($tenant->accountIsConfigured()) {
            $this->loadOrganization();
            if (request()->query('section') === 'field-travel' && app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved']) {
                $this->step = 4;
            }
        }
    }

    public function refreshSetup(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $tenant = Tenant::current()->refresh();
        $this->ensureMasterSetupQueued($tenant);
        if ($tenant->accountIsConfigured() && $this->organization === []) {
            $this->loadOrganization();
        }
    }

    /**
     * Sign-in provisioning finishes before this job runs. If that dispatch was missed,
     * or it was left on a queue the worker does not process, opening this page starts it.
     */
    private function ensureMasterSetupQueued(Tenant $tenant): void
    {
        if ($tenant->master_setup_status !== 'pending') {
            return;
        }

        if (! Cache::add('tenant-master-setup:'.$tenant->id, true, now()->addMinutes(2))) {
            return;
        }

        SeedTenantMasters::dispatch($tenant->id);
    }

    public function retrySetup(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $tenant = Tenant::current();
        $updated = Tenant::query()->whereKey($tenant->id)->where('master_setup_status', 'failed')
            ->update(['master_setup_status' => 'pending', 'master_setup_progress' => 0, 'master_setup_error' => null]);
        if ($updated) {
            SeedTenantMasters::dispatch($tenant->id);
        }
        $tenant->refresh();
    }

    protected function loadOrganization(): void
    {
        $organization = app(CompanyOnboardingService::class)->organization();
        $revision = app(CompanyLaunchService::class)->proposal($organization);
        $this->workflow = $revision?->mode->value ?? '';
        $this->workflowRevisionId = $revision?->id ?? 0;
        $this->workflowVersion = app(FieldPlanningSettingsService::class)->latest(auth()->user())?->version ?? 0;
        $this->organization = [
            'date_format' => app(DateFormatService::class)->dateFormat(),
            'timezone' => $organization?->timezone ?: 'Asia/Kolkata',
            'uses_divisions' => (int) ($organization?->metadata['uses_divisions'] ?? true),
            'name' => $organization?->name ?? Tenant::current()?->name,
            'email' => $organization?->email ?? auth()->user()->email,
            'phone' => $organization?->phone ?: (Tenant::current()?->registration_mobile ?? ''),
            'legal_name' => $organization?->legal_name ?? '',
            'gst_number' => $organization?->gst_number ?? '',
            'registration_number' => $organization?->registration_number ?? '',
            'website' => $organization?->website ?? '',
            'contact_person' => $organization?->contact_person ?? '',
            'contact_person_email' => $organization?->contact_person_email ?? '',
            'contact_person_phone' => $organization?->contact_person_phone ?? '',
        ];
        $this->approval = $organization?->metadata['company_setup']['approvals_draft'] ?? $this->approvalSetup()->defaults();
        if ($this->approvalSetup()->hasExistingConfiguration() || $this->approvalAlreadyApplied()) {
            $this->approval['required'] = 'existing';
        }
        if ($organization?->metadata['company_setup']['organization_saved'] ?? false) {
            $this->step = $revision ? 3 : 2;
        }
        if ($organization?->metadata['company_setup']['employees_reviewed'] ?? false) {
            $this->step = 4;
        }
        if ($organization?->metadata['company_setup']['field_travel_reviewed'] ?? false) {
            $this->step = 5;
        }
        if ($this->approvalAlreadyApplied()) {
            $this->approvalReview = ['required' => 'existing', 'flows' => []];
            $this->step = 6;
        }
        if (($organization?->metadata['company_setup']['approvals_draft']['required'] ?? '') !== '' && $this->step === 5) {
            try {
                $this->approvalReview = $this->approvalSetup()->review($this->approval);
                $this->step = 6;
            } catch (ValidationException $exception) {
                $this->addError('approval', 'Your saved approval setup needs attention. Review the approvers before continuing.');
            }
        }
        if (! $revision && ($organization?->metadata['company_setup']['organization_saved'] ?? false)) {
            $this->step = 2;
        }
        $draft = app(CompanyOnboardingService::class)->draft();
        if ($draft !== []) {
            $this->organization = array_replace($this->organization, array_intersect_key($draft['organization'], $this->organization));
            $this->workflow = $draft['workflow'] ?? '';
            $this->approval = $draft['approval'];
            $this->step = $draft['step'] === 7 && $this->requiresMonthlyPlans() ? 7 : min(5, $draft['step']);
            if ($this->step > 2 && $this->workflow !== $revision?->mode->value) {
                $this->step = 2;
            }
            if ($this->approvalSetup()->hasExistingConfiguration() || $this->approvalAlreadyApplied()) {
                $this->approval['required'] = 'existing';
            }
            if ($draft['workflow_version'] !== $this->workflowVersion) {
                $this->step = 2;
                $this->addError('workflow', 'The working method changed since your draft. Review and save your choice again.');
            }
            $this->draftSaved = true;
        }
        if ($this->step > 2 && $this->requiresMonthlyPlans()
            && (! ($organization?->metadata['company_setup']['territories_reviewed'] ?? false)
                || ! $this->checklist()['territory_count']
                || ! LocationMaster::query()->where('is_active', true)->has('territories')->exists())) {
            $this->step = 7;
        }
    }

    public function reviewFieldTravel(): void
    {
        app(CompanyOnboardingService::class)->mark('field_travel_reviewed');
        $this->resetErrorBag();
        $this->step = 5;
        $this->saveDraft();
    }

    public function next(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->resetErrorBag();
        if ($this->step === 0) {
            $this->step = 1;
        } elseif ($this->step === 2) {
            $this->saveWorkflow();
        } elseif ($this->step === 7) {
            $this->reviewTerritories();
        } elseif ($this->step === 3) {
            $checklist = $service->checklist();
            if (! $checklist['company'] || ! $checklist['employees']) {
                $this->addError('checklist', 'Add at least one employee to continue, or choose Continue later.');

                return;
            }
            $service->mark('employees_reviewed');
            $this->step = 4;
        }
        $this->saveDraft();
    }

    public function back(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->resetErrorBag();
        $this->step = match (true) {
            $this->step === 7 => 2,
            $this->step === 3 && $this->requiresMonthlyPlans() => 7,
            default => max(0, $this->step - 1),
        };
        $this->saveDraft();
    }

    public function saveOrganization(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->validate([
            'companyLogo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.date_format' => ['required', Rule::in(array_keys(DateFormatService::FORMATS))],
            'organization.timezone' => ['required', 'timezone'],
            'organization.uses_divisions' => ['required', 'boolean'],
            'organization.email' => ['required', 'email', 'max:255'],
            'organization.phone' => ['required', 'string', 'max:30'],
            'organization.legal_name' => ['nullable', 'string', 'max:255'],
            'organization.gst_number' => ['nullable', 'string', 'max:255'],
            'organization.registration_number' => ['nullable', 'string', 'max:255'],
            'organization.website' => ['nullable', 'url:http,https', 'max:255'],
            'organization.contact_person' => ['nullable', 'string', 'max:255'],
            'organization.contact_person_email' => ['nullable', 'email', 'max:255'],
            'organization.contact_person_phone' => ['nullable', 'string', 'max:255'],
        ]);
        $organization = $service->saveOrganization($this->organization);
        if ($this->companyLogo) {
            $path = $this->companyLogo->storePublicly('organization-logos', 'public');
            if (! $path) {
                $this->addError('companyLogo', 'The logo could not be saved. Please try again.');

                return;
            }
            $organization->update(['logo' => $path]);
            $this->reset('companyLogo');
        }
        $this->step = 2;
        $this->saveDraft();
        Notification::make()->title('Company information saved')->success()->send();
    }

    public function organizationDetailsUrl(): ?string
    {
        $organization = app(CompanyOnboardingService::class)->organization();

        return $organization ? OrganizationResource::getUrl('edit', ['record' => $organization], panel: 'admin') : null;
    }

    public function checklist(): array
    {
        return app(CompanyOnboardingService::class)->checklist();
    }

    public function finish(): void
    {
        app(CompanyLaunchService::class)->launch($this->launchMembers, $this->workflowRevisionId);
        app(CompanyOnboardingService::class)->forgetDraft();
        Notification::make()->title('Selected team launched')->success()->send();
        $this->redirect('/admin');
    }

    public function skip(): void
    {
        $this->saveDraft(deferred: true);
        if (app(CompanyOnboardingService::class)->organization()) {
            app(CompanyOnboardingService::class)->mark('deferred');
        }
        $this->redirect('/admin');
    }
}
