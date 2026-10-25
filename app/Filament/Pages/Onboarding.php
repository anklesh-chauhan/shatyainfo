<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Jobs\SeedTenantMasters;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\AccountOnboardingService;
use App\Services\Approval\OnboardingApprovalService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\AccountImportTemplate;
use App\Services\DataImport\ImportDownloadResponse;
use App\Services\DataImport\TerritoryImportTemplate;
use App\Services\TerritoryOnboardingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Onboarding extends Page
{
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
    public array $accountImportResult = [];

    public function updatedAccountFile(): void
    {
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
        $service = app(AccountOnboardingService::class);
        $service->authorize();
        $this->accountPreview = [];
        $this->accountImportResult = [];
        $this->validate(['accountFile' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048']]);
        $this->accountPreview = $service->preview($service->readFile($this->accountFile->getRealPath(), strtolower($this->accountFile->getClientOriginalExtension()) === 'xlsx'));
    }

    public function importAccounts(): void
    {
        app(AccountOnboardingService::class)->authorize();
        abort_unless($this->accountPreview !== [] && $this->accountPreview['errors'] === [], 422, 'Preview a valid file first.');
        $this->accountImportResult = app(AccountOnboardingService::class)->import($this->accountPreview['rows']);
        $this->accountPreview = [];
        $this->reset('accountFile');
        Notification::make()->title($this->accountImportResult['created'].' accounts created; '.$this->accountImportResult['updated'].' existing accounts updated.')->success()->send();
    }

    public function downloadAccountErrors(): StreamedResponse
    {
        app(AccountOnboardingService::class)->authorize();

        return ImportDownloadResponse::from(response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Row', 'External reference', 'Error'], ',', '"', '');
            foreach ($this->accountPreview['errors'] ?? [] as $error) {
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
        Notification::make()->title($count.' territories created. Existing territories preserved.')->success()->send();
    }

    public function skipTerritories(): void
    {
        app(CompanyOnboardingService::class)->mark('territories_reviewed');
        $this->resetErrorBag();
        $this->step = 3;
    }

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $navigationLabel = 'Setup Checklist';

    protected static ?string $title = 'Setup Checklist';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.pages.onboarding';

    public array $organization = [];

    public array $approval = [];

    #[Locked]
    public array $approvalReview = [];

    public function approvalSetup(): OnboardingApprovalService
    {
        return app(OnboardingApprovalService::class);
    }

    public function updatedApproval(mixed $value, ?string $key): void
    {
        if ($key !== null && str_starts_with($key, 'overrides.') && str_ends_with($key, '.custom') && $value) {
            $module = explode('.', $key)[1];
            if (array_key_exists($module, $this->approvalSetup()->moduleOptions())) {
                $this->approval['overrides'][$module] = array_merge($this->approval['common'], ['custom' => true]);
            }
        }
    }

    public function reviewApprovals(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->approvalReview = $this->approvalSetup()->saveDraft($this->approval);
        $this->step = 6;
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
        $this->organization = [
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
            $this->step = ($organization?->metadata['company_setup']['territories_reviewed'] ?? false) ? 3 : 2;
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
    }

    public function reviewFieldTravel(): void
    {
        app(CompanyOnboardingService::class)->mark('field_travel_reviewed');
        $this->resetErrorBag();
        $this->step = 5;
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
            if (! $service->checklist()['territory_count']) {
                $this->addError('territories', 'Add territories or choose Set up later.');

                return;
            }
            $service->mark('territories_reviewed');
            $this->step = 3;
        } elseif ($this->step === 3) {
            $checklist = $service->checklist();
            if (! $checklist['company'] || ! $checklist['employees']) {
                $this->addError('checklist', 'Add at least one employee to continue, or choose Continue later.');

                return;
            }
            $service->mark('employees_reviewed');
            $this->step = 4;
        }
    }

    public function back(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->resetErrorBag();
        $this->step = max(0, $this->step - 1);
    }

    public function saveOrganization(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()->accountIsConfigured(), 409);
        $this->validate([
            'companyLogo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
            'organization.name' => ['required', 'string', 'max:255'],
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
        app(CompanyOnboardingService::class)->finish();
        $this->redirect('/admin');
    }

    public function skip(): void
    {
        app(CompanyOnboardingService::class)->mark('deferred');
        $this->redirect('/admin');
    }
}
