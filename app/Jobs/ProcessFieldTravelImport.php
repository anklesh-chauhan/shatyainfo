<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DataImport\FieldTravelImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Jobs\TenantAware;
use Throwable;

class ProcessFieldTravelImport implements ShouldQueue, TenantAware
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $token, public int $userId, public ?int $tenantId) {}

    public function handle(FieldTravelImport $importer): void
    {
        if ((Tenant::current()?->id) !== $this->tenantId || ! preg_match('/^[a-f0-9-]{36}$/', $this->token)) {
            throw new \RuntimeException('Import company context does not match.');
        }
        $path = 'field-travel-imports/'.$this->userId.'/'.$this->token.'.json';
        Cache::lock('field-travel-import:'.($this->tenantId ?? 'landlord').':'.$this->token, 660)->get(function () use ($importer, $path): void {
            $payload = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            if ($payload['status'] !== 'queued') {
                return;
            }
            $previous = Auth::user();
            try {
                Auth::setUser(User::findOrFail($this->userId));
                $payload['result'] = $importer->import($payload['rows'], $payload['fingerprint']);
                $payload['status'] = 'complete';
                unset($payload['rows']);
            } catch (Throwable $exception) {
                $payload['status'] = 'failed';
                $payload['errors'] = $exception instanceof ValidationException ? $exception->validator->errors()->all() : ['Import failed. No rows were saved. Review the application log and preview again.'];
                report($exception);
            } finally {
                if ($previous) {
                    Auth::setUser($previous);
                } else {
                    Auth::forgetUser();
                }
                Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        if (Tenant::current()?->id !== $this->tenantId) {
            return;
        }
        $path = 'field-travel-imports/'.$this->userId.'/'.$this->token.'.json';
        if (! Storage::disk('local')->exists($path)) {
            return;
        }
        $payload = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        if ($payload['status'] === 'queued') {
            $payload['status'] = 'failed';
            $payload['errors'] = ['The background job stopped before completion. Preview again to check current records before retrying.'];
            Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }
}
