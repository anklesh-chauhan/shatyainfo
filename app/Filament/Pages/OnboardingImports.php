<?php

namespace App\Filament\Pages;

use App\Services\DataImport\ImportDownloadResponse;
use App\Services\DataImport\OptionalOnboardingImport;
use App\Services\DataImport\OptionalOnboardingTemplate;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnboardingImports extends Page
{
    use WithFileUploads;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Optional Setup Imports';

    protected string $view = 'filament.pages.onboarding-imports';

    public string $module = 'products';

    #[Locked]
    public ?string $group = null;

    public $file;

    #[Locked]
    public array $rows = [];

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $result = [];

    public static function canAccess(): bool
    {
        return Onboarding::canAccess();
    }

    public function mount(?string $group = null): void
    {
        app(OptionalOnboardingImport::class)->authorize();
        abort_unless($group === null || in_array($group, ['products', 'hr'], true), 422);
        $this->group = $group;
        if ($group !== null) {
            $this->module = array_key_first($this->moduleOptions());

            return;
        }
        $module = request()->query('module', 'products');
        $this->module = is_string($module) && isset(OptionalOnboardingImport::MODULES[$module]) ? $module : 'products';
    }

    public function getView(): string
    {
        return $this->group === null ? parent::getView() : 'filament.pages.partials.onboarding-optional-import';
    }

    public function moduleOptions(): array
    {
        $keys = match ($this->group) {
            'products' => ['products', 'inventory_openings'],
            'hr' => ['shifts', 'holidays', 'leave_openings'],
            default => array_keys(OptionalOnboardingImport::MODULES),
        };

        return array_intersect_key(OptionalOnboardingImport::MODULES, array_flip($keys));
    }

    public function updatedModule(): void
    {
        $this->file = null;
        $this->resetPreview();
    }

    public function updatedFile(): void
    {
        $this->resetPreview();
    }

    private function resetPreview(): void
    {
        $this->resetErrorBag();
        $this->rows = [];
        $this->preview = [];
        $this->result = [];
    }

    public function downloadTemplate(): StreamedResponse
    {
        $service = app(OptionalOnboardingImport::class);
        $service->authorize();
        $service->validateModule($this->module);
        $module = $this->module;

        return ImportDownloadResponse::from(response()->streamDownload(function () use ($module): void {
            $path = tempnam(sys_get_temp_dir(), 'optional-setup-');
            try {
                app(OptionalOnboardingTemplate::class)->write($module, $path);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, $module.'-setup.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
    }

    public function previewFile(): void
    {
        $service = app(OptionalOnboardingImport::class);
        $service->authorize();
        $service->validateModule($this->module);
        $this->resetPreview();
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048']]);
        $this->rows = $service->read($this->module, $this->file->getRealPath(), strtolower($this->file->getClientOriginalExtension()) === 'xlsx');
        $this->preview = $service->preview($this->module, $this->rows);
    }

    public function importReviewed(): void
    {
        $service = app(OptionalOnboardingImport::class);
        $service->authorize();
        if ($this->rows === [] || empty($this->preview['fingerprint']) || ! empty($this->preview['errors'])) {
            throw ValidationException::withMessages(['file' => 'Preview a valid file before confirming the import.']);
        }
        $this->result = $service->import($this->module, $this->rows, $this->preview['fingerprint']);
        $this->rows = [];
        $this->preview = [];
        $this->file = null;
        Notification::make()->title('Setup import completed')->success()->send();
    }

    public function downloadErrors(): StreamedResponse
    {
        app(OptionalOnboardingImport::class)->authorize();
        $errors = $this->preview['errors'] ?? [];

        return ImportDownloadResponse::from(response()->streamDownload(function () use ($errors): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Row', 'Error'], escape: '');
            foreach ($errors as $error) {
                $message = $error['message'];
                if (preg_match('/^[=+@\-\t\r]/', $message)) {
                    $message = "'".$message;
                }
                fputcsv($stream, [$error['row'], $message], escape: '');
            }
            fclose($stream);
        }, 'setup-import-errors.csv', ['Content-Type' => 'text/csv']));
    }
}
