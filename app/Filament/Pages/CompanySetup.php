<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanySetup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    use WithFileUploads;

    protected static ?string $navigationLabel = 'Company Setup';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.company-setup';

    public array $organization = [];

    public array $rows = [];

    public $file;

    public int $step = 1;

    public int $savedCount = 0;

    public static function canAccess(): bool
    {
        return Tenant::checkCurrent() && (auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) ?? false);
    }

    public function mount(): void
    {
        $organization = app(CompanyOnboardingService::class)->organization();
        $this->organization = [
            'name' => $organization?->name ?? Tenant::current()?->name,
            'email' => $organization?->email ?? auth()->user()->email,
            'phone' => $organization?->phone ?? '',
        ];
        $this->step = ($organization?->metadata['company_setup']['organization_saved'] ?? false) ? 2 : 1;
        $this->addRow();
    }

    public function saveOrganization(): void
    {
        $this->validate([
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.email' => ['required', 'email', 'max:255'],
            'organization.phone' => ['required', 'string', 'max:30'],
        ]);
        app(CompanyOnboardingService::class)->saveOrganization($this->organization);
        $this->step = 2;
    }

    public function editOrganization(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->step = 1;
    }

    public function addRow(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        if (count($this->rows) < 500) {
            $this->rows[] = array_fill_keys(CompanyOnboardingService::EMPLOYEE_COLUMNS, '');
        }
    }

    public function removeRow(int $index): void
    {
        app(CompanyOnboardingService::class)->authorize();
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function previewImport(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $this->rows = app(CompanyOnboardingService::class)->parseCsv($this->file->getRealPath());
        $this->resetErrorBag();
    }

    public function saveEmployees(): void
    {
        $count = app(CompanyOnboardingService::class)->saveEmployees($this->rows);
        $this->savedCount += $count;
        $this->rows = [];
        $this->file = null;
        $this->addRow();
        Notification::make()->title($count.' employees saved')->success()->send();
    }

    public function finish(): void
    {
        if (collect($this->rows)->contains(fn (array $row): bool => collect($row)->contains(fn ($value): bool => trim((string) $value) !== ''))) {
            throw ValidationException::withMessages(['rows' => 'Save your employee rows before finishing, or remove the unsaved rows.']);
        }

        $service = app(CompanyOnboardingService::class);
        abort_unless($service->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422);
        $service->mark('completed');
        $this->redirect('/admin');
    }

    public function skip(): void
    {
        app(CompanyOnboardingService::class)->mark('deferred');
        $this->redirect('/admin');
    }

    public function downloadTemplate(): StreamedResponse
    {
        app(CompanyOnboardingService::class)->authorize();

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, CompanyOnboardingService::EMPLOYEE_COLUMNS, escape: '');
            fputcsv($output, ['EMP001', 'Asha', 'Rao', 'asha@example.com', '9876543210'], escape: '');
            fclose($output);
        }, 'employee-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
