<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ApplyRegistrationOnboarding implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 360;

    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $tenantId)
    {
        $this->onConnection('database');
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant || $tenant->onboarding_status === 'failed' || ! is_array($tenant->onboarding_data)) {
            return;
        }
        if (in_array($tenant->provisioning_status, ['pending', 'provisioning'], true)) {
            $this->release(15);

            return;
        }
        if ($tenant->provisioning_status !== 'ready') {
            return;
        }
        $data = $tenant->onboarding_data;
        $hash = hash('sha256', json_encode($data));
        $guard = Auth::guard('web');
        $previousUser = $guard->user();
        try {
            $tenant->execute(function () use ($data, $hash, $guard): void {
                $owner = User::query()->orderBy('id')->firstOrFail();
                $guard->setUser($owner);
                DB::transaction(function () use ($data, $hash): void {
                    $organization = Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                    if (($organization->metadata['company_setup']['registration_hash'] ?? null) === $hash) {
                        return;
                    }
                    $service = app(CompanyOnboardingService::class);
                    if ($data['skip'] ?? false) {
                        $service->mark('deferred');
                    } else {
                        $service->saveOrganization($data['organization']);
                        if (! empty($data['employees'])) {
                            $service->saveEmployees($data['employees']);
                        }
                        $service->mark('completed');
                    }
                    $organization->refresh();
                    $metadata = $organization->metadata ?? [];
                    $metadata['company_setup']['registration_hash'] = $hash;
                    $organization->metadata = $metadata;
                    $organization->save();
                });
            });
            $tenant->forceFill(['onboarding_status' => 'applied', 'onboarding_data' => null])->saveQuietly();
        } catch (\Throwable $exception) {
            $tenant->forceFill(['onboarding_status' => 'failed'])->saveQuietly();

            throw $exception;
        } finally {
            $guard->forgetUser();
            if ($previousUser) {
                $guard->setUser($previousUser);
            }
        }
    }
}
