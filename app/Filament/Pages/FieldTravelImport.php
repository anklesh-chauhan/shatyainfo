<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessFieldTravelImport;
use App\Models\Patch;
use App\Models\StandardFareChart;
use App\Models\Tenant;
use App\Models\TravelSegment;
use App\Services\DataImport\FieldTravelImport as Importer;
use App\Services\DataImport\FieldTravelTemplate;
use App\Services\DataImport\ImportDownloadResponse;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FieldTravelImport extends Page
{
    use WithFileUploads;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Patches & Travel Import';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?string $title = 'Patches & Travel Import';

    protected string $view = 'filament.pages.field-travel-import';

    public $file;

    public bool $historical = false;

    #[Locked]
    public bool $embedded = false;

    public int $previewPage = 1;

    #[Locked]
    public array $preview = [];

    #[Locked]
    public ?string $token = null;

    #[Locked]
    public string $status = '';

    #[Locked]
    public array $result = [];

    #[Locked]
    public array $progress = [];

    #[Locked]
    public array $importErrors = [];

    public static function canAccess(): bool
    {
        return auth()->check() && collect([Patch::class, StandardFareChart::class, TravelSegment::class])->contains(fn ($class) => Gate::allows('create', $class));
    }

    public function mount(bool $embedded = false): void
    {
        abort_unless(static::canAccess(), 403);
        $this->embedded = $embedded;
        if (! $embedded) {
            $this->redirect(DataImport::getUrl(['module' => 'field_routes'], panel: 'admin'));

            return;
        }
        abort_unless(Onboarding::canAccess(), 403);
        $token = session($this->sessionKey());
        if (is_string($token) && preg_match('/^[a-f0-9-]{36}$/', $token)) {
            $this->token = $token;
            if (Storage::disk('local')->exists($this->payloadPath())) {
                $this->refreshStatus();
                if ($this->status === 'preview') {
                    $this->resetPreview();
                }
            } else {
                $this->resetPreview();
            }
        }
    }

    public function getView(): string
    {
        return $this->embedded ? 'filament.pages.partials.onboarding-field-import' : parent::getView();
    }

    private function sessionKey(): string
    {
        return 'field-travel-import.'.(Tenant::current()?->id ?? 'landlord').'.'.auth()->id();
    }

    public function updatedFile(): void
    {
        $this->ensureImportIdle();
        $this->resetPreview();
    }

    public function updatedHistorical(): void
    {
        $this->ensureImportIdle();
        $this->file = null;
        $this->resetPreview();
    }

    private function ensureImportIdle(): void
    {
        $token = session($this->sessionKey());
        if (is_string($token) && preg_match('/^[a-f0-9-]{36}$/', $token)) {
            $path = 'field-travel-imports/'.auth()->id().'/'.$token.'.json';
            if (Storage::disk('local')->exists($path)) {
                $payload = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
                if (in_array($payload['status'], ['queued', 'running'], true)) {
                    $this->token = $token;
                    $this->refreshStatus();
                    throw ValidationException::withMessages(['file' => 'Your field travel import is still in progress. Wait for it to finish.']);
                }
            }
        }
    }

    private function resetPreview(): void
    {
        $this->resetErrorBag();
        $this->previewPage = 1;
        $this->preview = [];
        $this->token = null;
        $this->status = '';
        $this->importErrors = [];
        $this->result = [];
    }

    public function downloadTemplate(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $historical = $this->historical;

        return ImportDownloadResponse::from(response()->streamDownload(function () use ($historical): void {
            $path = tempnam(sys_get_temp_dir(), 'field-travel-');
            try {
                app(FieldTravelTemplate::class)->write($path, $historical);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, $historical ? 'historical-travel.xlsx' : 'patches-and-fares.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
    }

    public function previewFile(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->ensureImportIdle();
        $this->resetPreview();
        $this->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        $importer = app(Importer::class);
        try {
            $rows = $importer->read($this->file->getRealPath(), $this->historical);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'Unable to read this workbook. Upload a valid .xlsx template.']);
        }
        $this->historical = isset($rows['Travel Segments']);
        $preview = $importer->preview($rows);
        $this->importErrors = $preview['errors'];
        $this->preview = array_map(fn ($plan) => array_intersect_key($plan, array_flip(['sheet', 'row', 'key', 'action', 'values'])), $preview['plans']);
        if ($this->importErrors !== []) {
            return;
        }
        $this->token = (string) Str::uuid();
        $this->status = 'preview';
        Storage::disk('local')->put($this->payloadPath(), json_encode(['status' => 'preview', 'rows' => $rows, 'fingerprint' => $preview['fingerprint']], JSON_THROW_ON_ERROR));
    }

    public function importReviewed(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->token && in_array($this->status, ['queued', 'running', 'complete'], true)) {
            $this->refreshStatus();

            return;
        }
        if (! $this->token || $this->status !== 'preview') {
            throw ValidationException::withMessages(['file' => 'Preview your workbook first.']);
        }
        Cache::lock('field-travel-dispatch:'.(Tenant::current()?->id ?? 'landlord').':'.$this->token, 30)->block(5, function (): void {
            $payload = json_decode(Storage::disk('local')->get($this->payloadPath()), true, flags: JSON_THROW_ON_ERROR);
            if ($payload['status'] !== 'preview') {
                $this->refreshStatus();

                return;
            }
            $payload['status'] = 'queued';
            Storage::disk('local')->put($this->payloadPath(), json_encode($payload, JSON_THROW_ON_ERROR));
            $this->status = 'queued';
            session()->put($this->sessionKey(), $this->token);
            $job = new ProcessFieldTravelImport($this->token, (int) auth()->id(), Tenant::current()?->id);
            try {
                dispatch($job);
            } catch (\Throwable $exception) {
                $payload['status'] = 'preview';
                Storage::disk('local')->put($this->payloadPath(), json_encode($payload, JSON_THROW_ON_ERROR));
                $this->status = 'preview';
                report($exception);
                throw ValidationException::withMessages(['file' => 'The background job could not be queued. Check the queue connection and try again.']);
            }
            $this->preview = [];
            $this->refreshStatus();
        });
    }

    public function refreshStatus(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->token) {
            return;
        }
        $payload = json_decode(Storage::disk('local')->get($this->payloadPath()), true, flags: JSON_THROW_ON_ERROR);
        $this->status = $payload['status'];
        $this->result = $payload['result'] ?? [];
        $job = new ProcessFieldTravelImport($this->token, (int) auth()->id(), Tenant::current()?->id);
        $this->progress = Cache::store('file')->get($job->progressKey(), ['processed' => 0, 'total' => array_sum(array_map('count', $payload['rows'] ?? []))]);
        $this->importErrors = $payload['errors'] ?? [];
    }

    private function payloadPath(): string
    {
        return 'field-travel-imports/'.auth()->id().'/'.$this->token.'.json';
    }
}
