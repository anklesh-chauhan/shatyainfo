<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GlobalConfiguration\OrganizationAndCompanyCluster;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\EmployeeImportTemplate;
use App\Services\DataImport\ImportableModule;
use App\Services\DataImport\ImportDownloadResponse;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OpenSpout\Common\Entity\Row;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataImport extends Page implements HasForms
{
    protected static ?string $cluster = OrganizationAndCompanyCluster::class;

    use InteractsWithForms;

    protected static ?string $navigationLabel = 'Data Import';

    protected static ?string $title = 'Data Import';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected string $view = 'filament.pages.data-import';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array{created: int, updated: int, failed: list<array{row: int, message: string}>}
     */
    public array $result = [
        'created' => 0,
        'updated' => 0,
        'failed' => [],
    ];

    public bool $hasResult = false;

    #[Locked]
    public ?array $employeePreview = null;

    #[Locked]
    public ?string $employeePreviewHash = null;

    #[Locked]
    public bool $embedded = false;

    public string $importType = 'employees';

    public function importOptions(): array
    {
        return [
            'territories' => 'Territories & Divisions',
            'employees' => 'Employees & Reporting Hierarchy',
            'accounts' => 'Accounts / Customers / Distributors',
            'field_routes' => 'Field Routes & Historical Travel',
            'products' => 'Products, Samples & Opening Inventory',
            'hr' => 'Shift Assignments, Holidays & Opening Leave Balances',
        ];
    }

    public function updatedImportType(): void
    {
        abort_unless(isset($this->importOptions()[$this->importType]), 422);
        $this->hasResult = false;
        $this->clearEmployeePreview();
        $this->resetErrorBag();
        $this->form->fill(['resource' => EmployeeResource::class, 'file' => null]);
    }

    public static function canAccess(): bool
    {
        return Onboarding::canAccess();
    }

    public function mount(bool $embedded = false): void
    {
        abort_unless(static::canAccess(), 403);
        $this->embedded = $embedded;
        $module = request()->query('module', 'employees');
        $this->importType = is_string($module) && isset($this->importOptions()[$module]) ? $module : 'employees';
        $this->form->fill([
            'resource' => EmployeeResource::class,
            'file' => null,
        ]);
    }

    public function getView(): string
    {
        return $this->embedded ? 'filament.pages.partials.onboarding-employee-import' : parent::getView();
    }

    public function updatedData(): void
    {
        $this->clearEmployeePreview();
    }

    private function clearEmployeePreview(): void
    {
        $this->employeePreview = null;
        $this->employeePreviewHash = null;
    }

    public function previewEmployees(ResourceCsvImporter $importer, ResourceImportCatalog $catalog): void
    {
        $this->authorizeEmployeeImport();
        $this->clearEmployeePreview();
        $state = $this->form->getState();
        $module = $catalog->find((string) ($state['resource'] ?? ''));
        abort_unless($module?->modelClass === Employee::class && $catalog->allows($module), 403);
        [$path] = $this->resolveFile($state['file'] ?? null);
        $this->employeePreview = $importer->previewEmployees($module, $path);
        $this->employeePreviewHash = hash_file('sha256', $path);
    }

    private function authorizeEmployeeImport(): void
    {
        abort_unless(static::canAccess() && $this->importType === 'employees', 403);
        abort_unless(($this->data['resource'] ?? null) === EmployeeResource::class, 403);
        abort_unless(Tenant::current()?->accountIsConfigured(), 409);
        abort_unless(app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('resource')->default(EmployeeResource::class),
                FileUpload::make('file')
                    ->afterStateUpdated(function (): void {
                        $this->clearEmployeePreview();
                    })
                    ->label('Excel or CSV file')
                    ->disk('local')
                    ->directory('data-imports')
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'text/x-csv',
                        'application/csv',
                        'application/x-csv',
                        'text/comma-separated-values',
                        'text/x-comma-separated-values',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ])
                    ->maxSize(20480)
                    ->required()
                    ->helperText('Upload .xlsx or UTF-8 CSV. Employee imports read the Employees sheet; other modules read the first sheet. Read the template Instructions before uploading.'),
            ])
            ->statePath('data');
    }

    public function selectedModule(): ?ImportableModule
    {
        $resource = $this->data['resource'] ?? null;

        if ($resource !== EmployeeResource::class) {
            return null;
        }

        return app(ResourceImportCatalog::class)->find($resource);
    }

    public function downloadExample(ResourceCsvImporter $importer): ?StreamedResponse
    {
        abort_unless(static::canAccess() && $this->importType === 'employees', 403);
        $module = $this->selectedModule();

        if ($module === null) {
            Notification::make()
                ->title('Choose a module first.')
                ->warning()
                ->send();

            return null;
        }

        $csv = $importer->exampleCsv($module);
        if ($module->modelClass === Employee::class) {
            return ImportDownloadResponse::from(response()->streamDownload(function (): void {
                $path = tempnam(sys_get_temp_dir(), 'employee-template-');
                try {
                    app(EmployeeImportTemplate::class)->write($path);
                    readfile($path);
                } finally {
                    unlink($path);
                }
            }, 'employees-import.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
        }
        $filename = str($module->label)->slug().'-import.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(ResourceCsvImporter $importer, ResourceImportCatalog $catalog): void
    {
        $this->authorizeEmployeeImport();
        $state = $this->form->getState();
        $module = $catalog->find((string) ($state['resource'] ?? ''));

        if ($module === null || $module->modelClass !== Employee::class || ! $catalog->allows($module)) {
            Notification::make()
                ->title('You cannot import this module.')
                ->danger()
                ->send();

            return;
        }

        [$absolutePath, $relativePath] = $this->resolveFile($state['file'] ?? null);

        if ($this->employeePreview === null || $this->employeePreview['errors'] !== [] || $this->employeePreview['count'] === 0 || ! hash_equals($this->employeePreviewHash ?? '', hash_file('sha256', $absolutePath))) {
            throw ValidationException::withMessages(['data.file' => 'Preview the current file before confirming the import.']);
        }

        try {
            $result = $importer->import($module, $absolutePath);
        } finally {
            if (is_string($relativePath)) {
                Storage::disk('local')->delete($relativePath);
            }
        }

        $this->result = $result->toArray();
        $this->hasResult = true;
        $this->clearEmployeePreview();

        if ($result->importedCount() === 0 && $result->failed === []) {
            Notification::make()
                ->title('The file has no data rows.')
                ->warning()
                ->send();
        } else {
            $body = $result->created.' created, '.$result->updated.' updated.';

            if ($result->failed !== []) {
                $body .= ' '.count($result->failed).' failed.';
                foreach (array_slice($result->failed, 0, 3) as $failure) {
                    $body .= "\nRow ".$failure['row'].': '.$failure['message'];
                }
                if (count($result->failed) > 3) {
                    $body .= $this->embedded
                        ? "\nSee Rows to fix below for the remaining errors."
                        : "\nSee Last import for the remaining errors.";
                }
            }

            $notification = Notification::make()
                ->title($result->failed === [] ? 'Import finished' : ($result->importedCount() === 0 ? 'Import failed' : 'Import completed with errors'))
                ->body(nl2br(e($body)));

            if ($result->failed !== [] && $result->importedCount() === 0) {
                $notification->danger()->persistent();
            } elseif ($result->failed !== []) {
                $notification->warning()->persistent();
            } else {
                $notification->success();
            }

            $notification->send();
        }

        $this->form->fill([
            'resource' => $state['resource'],
            'file' => null,
        ]);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveFile(mixed $file): array
    {
        if (is_array($file)) {
            return $this->resolveFile(Arr::first($file));
        }

        if ($file instanceof TemporaryUploadedFile) {
            $path = $file->getRealPath();

            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Choose an Excel or CSV file.');
            }

            return [$path, null];
        }

        if (! is_string($file) || $file === '') {
            throw new RuntimeException('Choose an Excel or CSV file.');
        }

        return [Storage::disk('local')->path($file), $file];
    }
}
