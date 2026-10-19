<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessFieldTravelImport;
use App\Models\Patch;
use App\Models\StandardFareChart;
use App\Models\Tenant;
use App\Models\TravelSegment;
use App\Services\DataImport\FieldTravelImport as Importer;
use App\Services\DataImport\FieldTravelTemplate;
use Filament\Pages\Page;
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

    protected static ?string $navigationLabel = 'Patches & Travel Import';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?string $title = 'Patches & Travel Import';

    protected string $view = 'filament.pages.field-travel-import';

    public $file;

    public bool $historical = false;

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
    public array $importErrors = [];

    public static function canAccess(): bool
    {
        return auth()->check() && collect([Patch::class, StandardFareChart::class, TravelSegment::class])->contains(fn ($class) => Gate::allows('create', $class));
    }

    public function mount(): void
    {
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

    private function sessionKey(): string
    {
        return 'field-travel-import.'.(Tenant::current()?->id ?? 'landlord').'.'.auth()->id();
    }

    public function updatedFile(): void
    {
        $this->resetPreview();
    }

    public function updatedHistorical(): void
    {
        $this->file = null;
        $this->resetPreview();
    }

    private function resetPreview(): void
    {
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

        return response()->streamDownload(function () use ($historical): void {
            $path = tempnam(sys_get_temp_dir(), 'field-travel-');
            try {
                app(FieldTravelTemplate::class)->write($path, $historical);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, $historical ? 'historical-travel.xlsx' : 'patches-and-fares.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function previewFile(): void
    {
        abort_unless(static::canAccess(), 403);
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
        if (! $this->token || $this->status !== 'preview') {
            throw ValidationException::withMessages(['file' => 'Preview your workbook first.']);
        }
        $payload = json_decode(Storage::disk('local')->get($this->payloadPath()), true, flags: JSON_THROW_ON_ERROR);
        if ($payload['status'] !== 'preview') {
            $this->refreshStatus();

            return;
        }
        $large = count($this->preview) > 100;
        if ($large && in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null', null], true)) {
            throw ValidationException::withMessages(['file' => 'For more than 100 rows configure a background queue and start its worker, or split the workbook.']);
        }
        $payload['status'] = 'queued';
        Storage::disk('local')->put($this->payloadPath(), json_encode($payload, JSON_THROW_ON_ERROR));
        $this->status = 'queued';
        session()->put($this->sessionKey(), $this->token);
        $job = new ProcessFieldTravelImport($this->token, (int) auth()->id(), Tenant::current()?->id);
        if ($large) {
            try {
                dispatch($job);
            } catch (\Throwable $exception) {
                $payload['status'] = 'preview';
                Storage::disk('local')->put($this->payloadPath(), json_encode($payload, JSON_THROW_ON_ERROR));
                $this->status = 'preview';
                report($exception);
                throw ValidationException::withMessages(['file' => 'The background job could not be queued. Check the queue connection and try again.']);
            }
        } else {
            $job->handle(app(Importer::class));
        }
        $this->refreshStatus();
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
        $this->importErrors = $payload['errors'] ?? [];
    }

    private function payloadPath(): string
    {
        return 'field-travel-imports/'.auth()->id().'/'.$this->token.'.json';
    }
}
