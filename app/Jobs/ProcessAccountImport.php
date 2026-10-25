<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountOnboardingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Jobs\TenantAware;
use Throwable;

class ProcessAccountImport implements ShouldQueue, TenantAware
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public string $token, public int $userId, public ?int $tenantId)
    {
        $this->onConnection('database');
    }

    public static function statusKey(int $userId, ?int $tenantId): string
    {
        return 'account-import:'.($tenantId ?? 'central').':'.$userId;
    }

    public function payloadPath(): string
    {
        return 'account-imports/'.($this->tenantId ?? 'central').'/'.$this->userId.'/'.$this->token.'.json';
    }

    public function handle(AccountOnboardingService $importer): void
    {
        if (Tenant::current()?->id !== $this->tenantId) {
            throw new \RuntimeException('Import company context does not match.');
        }
        $key = self::statusKey($this->userId, $this->tenantId);
        Cache::store('file')->lock($key.':worker', 3700)->get(function () use ($importer, $key): void {
            $status = Cache::store('file')->get($key);
            if (($status['token'] ?? null) !== $this->token || ($status['status'] ?? null) !== 'queued') {
                return;
            }
            $previous = Auth::user();
            try {
                Auth::setUser(User::findOrFail($this->userId));
                $this->updateStatus(['status' => 'running', 'message' => 'Validating accounts', 'processed' => 0]);
                $rows = json_decode(Storage::disk('local')->get($this->payloadPath()), true, flags: JSON_THROW_ON_ERROR);
                $result = $importer->import($rows, function (string $stage, int $processed, int $total): void {
                    $this->updateStatus(['message' => $stage, 'processed' => $processed, 'total' => $total]);
                });
                $this->updateStatus(['status' => 'complete', 'message' => 'Import completed', 'processed' => count($rows), 'result' => $result]);
            } catch (Throwable $exception) {
                $this->failed($exception);
                throw $exception;
            } finally {
                if ($previous) {
                    Auth::setUser($previous);
                } else {
                    Auth::forgetUser();
                }
                Storage::disk('local')->delete($this->payloadPath());
            }
        });
    }

    private function updateStatus(array $updates): void
    {
        $key = self::statusKey($this->userId, $this->tenantId);
        $status = Cache::store('file')->get($key);
        if (($status['token'] ?? null) === $this->token && ($status['status'] ?? null) !== 'complete') {
            Cache::store('file')->put($key, array_replace($status, $updates), now()->addDays(7));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof ValidationException
            ? implode(' ', $exception->validator->errors()->all())
            : 'The import stopped before completion. Preview the file again before retrying. If this continues, contact your administrator.';
        $this->updateStatus(['status' => 'failed', 'message' => $message]);
    }
}
