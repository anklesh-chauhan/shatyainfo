<?php

namespace App\Models;

use App\Jobs\ManageCloudflareCname;
use App\Jobs\ProvisionTenant;
use App\Support\Tenancy\LandlordDomains;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    use HasApprovalWorkflow;

    /**
     * Owner credentials for the tenant being created. Kept off the model
     * attributes so they are never written to the landlord registry.
     *
     * @var array{name: string, email: string, password: string}|null
     */
    public static ?array $pendingOwner = null;

    protected $fillable = ['name', 'domain', 'cloudflare_record_id'];

    protected $hidden = [
        'db_password',
        'provisioning_owner',
        'onboarding_data',
        'master_setup_error',
    ];

    public function getDatabaseName(): string
    {
        return (string) $this->database;
    }

    public function accountIsConfigured(): bool
    {
        return ($this->master_setup_status ?? 'ready') === 'ready';
    }

    protected function casts(): array
    {
        return [
            'db_password' => 'encrypted',
            'provisioning_owner' => 'encrypted:array',
            'onboarding_data' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant) {
            $tenant->domain = LandlordDomains::normalize((string) $tenant->domain);

            if (LandlordDomains::isLandlordHost($tenant->domain)) {
                throw ValidationException::withMessages([
                    'domain' => 'That host is reserved for the landlord application.',
                ]);
            }
        });

        static::creating(function (Tenant $tenant) {
            if (blank($tenant->database)) {
                $tenant->database = 'tenant_'.uniqid();
            }

            if (blank($tenant->provisioning_status)) {
                $tenant->provisioning_status = 'pending';
            }
        });

        static::created(function (Tenant $tenant) {
            $owner = Tenant::$pendingOwner;
            Tenant::$pendingOwner = null;

            if (is_array($owner) && ($owner['email'] ?? '') !== '' && ($owner['password'] ?? '') !== '') {
                $tenant->forceFill([
                    'provisioning_owner' => $owner,
                ])->saveQuietly();
            }

            if ($tenant->provisioning_status !== 'awaiting_verification') {
                ProvisionTenant::dispatch($tenant->id);
            }
        });

        static::updating(function (Tenant $tenant) {
            foreach (['database', 'db_username', 'db_password'] as $attribute) {
                if ($tenant->isDirty($attribute)) {
                    $tenant->setAttribute($attribute, $tenant->getOriginal($attribute));
                }
            }
        });

        static::updated(function (Tenant $tenant) {
            if (! $tenant->wasChanged('domain')) {
                return;
            }

            ManageCloudflareCname::dispatch(
                tenantId: $tenant->id,
                name: (string) $tenant->name,
                domain: (string) $tenant->domain,
                action: 'update',
                cloudflareRecordId: $tenant->cloudflare_record_id,
                oldDomain: $tenant->getOriginal('domain'),
            );

            Log::info('Tenant domain changed.', [
                'tenant_id' => $tenant->id,
                'domain' => $tenant->domain,
            ]);
        });

        static::deleting(function (Tenant $tenant) {
            app(TenantDatabaseProvisioner::class)->drop($tenant);
        });

        static::deleted(function (Tenant $tenant) {
            ManageCloudflareCname::dispatch(
                tenantId: $tenant->id,
                name: (string) $tenant->name,
                domain: (string) $tenant->domain,
                action: 'delete',
                cloudflareRecordId: $tenant->cloudflare_record_id,
            );

            Log::info('Tenant deleted.', ['tenant_id' => $tenant->id]);
        });
    }
}
