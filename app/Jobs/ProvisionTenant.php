<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Support\Tenancy\TenantProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Throwable;

class ProvisionTenant implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $tenantId)
    {
        $this->afterCommit();
        $this->onConnection('database');
    }

    public function handle(TenantProvisioner $provisioner): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant || in_array($tenant->provisioning_status, ['ready', 'awaiting_verification'], true)) {
            return;
        }

        $tenant->forceFill([
            'provisioning_status' => 'provisioning',
            'provisioning_error' => null,
        ])->saveQuietly();

        $owner = is_array($tenant->provisioning_owner) ? $tenant->provisioning_owner : null;

        try {
            $provisioner->provision($tenant, $owner);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $tenant->forceFill([
            'provisioning_status' => 'ready',
            'provisioning_error' => null,
            'provisioning_owner' => null,
        ])->saveQuietly();

    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    private function markFailed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant || $tenant->provisioning_status === 'ready') {
            return;
        }

        $message = $exception?->getMessage() ?: 'Company setup failed.';

        $tenant->forceFill([
            'provisioning_status' => 'failed',
            'provisioning_error' => mb_substr($message, 0, 2000),
        ])->saveQuietly();
    }
}
