<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

class SeedTenantMasters implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $tenantId)
    {
        $this->afterCommit();
        $this->onConnection('database');
        $this->onQueue('tenant-setup');
    }

    public function handle(TenantProvisioner $provisioner): void
    {
        $claimed = Tenant::query()->whereKey($this->tenantId)
            ->where('provisioning_status', 'ready')
            ->whereIn('master_setup_status', ['pending', 'failed'])
            ->update(['master_setup_status' => 'running', 'master_setup_step' => 'Preparing master data', 'master_setup_error' => null]);
        if (! $claimed) {
            return;
        }
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        try {
            $provisioner->configureAccount($tenant);
            $tenant->forceFill(['master_setup_status' => 'ready', 'master_setup_step' => null, 'master_setup_error' => null])->saveQuietly();
        } catch (Throwable $exception) {
            $this->failed($exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Tenant::query()->whereKey($this->tenantId)->where('master_setup_status', 'running')->update([
            'master_setup_status' => 'failed',
            'master_setup_step' => null,
            'master_setup_error' => mb_substr($exception?->getMessage() ?? 'Account configuration failed.', 0, 2000),
        ]);
        Log::error('Tenant master setup failed.', ['tenant_id' => $this->tenantId, 'message' => $exception?->getMessage()]);
    }
}
