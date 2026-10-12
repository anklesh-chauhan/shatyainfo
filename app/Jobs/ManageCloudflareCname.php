<?php

namespace App\Jobs;

use App\Models\Tenant;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ManageCloudflareCname implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $tenantId,
        public string $name,
        public string $domain,
        public string $action,
        public ?string $cloudflareRecordId = null,
        public ?string $oldDomain = null,
    ) {}

    public function handle(): void
    {
        $zoneId = config('multitenancy.cloudflare.zone_id');
        $apiToken = config('multitenancy.cloudflare.api_token');
        $target = config('multitenancy.cloudflare.target', 'iterp.in');

        if (! is_string($zoneId) || $zoneId === '' || ! is_string($apiToken) || $apiToken === '') {
            Log::info('Skipping Cloudflare DNS because it is not configured.', [
                'tenant_id' => $this->tenantId,
                'action' => $this->action,
            ]);

            return;
        }

        $recordName = $this->recordName();
        if ($recordName === null || $recordName === '') {
            Log::warning('Skipping Cloudflare DNS because the tenant domain has no record name.', [
                'tenant_id' => $this->tenantId,
                'domain' => $this->domain,
            ]);

            return;
        }

        try {
            $headers = [
                'Authorization' => 'Bearer '.$apiToken,
                'Content-Type' => 'application/json',
            ];

            if ($this->action === 'create') {
                $response = Http::withHeaders($headers)->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                    'type' => 'CNAME',
                    'name' => $recordName,
                    'content' => $target,
                    'ttl' => 3600,
                    'proxied' => true,
                ]);

                if ($response->successful()) {
                    $recordId = $response->json('result.id');
                    Tenant::query()->whereKey($this->tenantId)->update([
                        'cloudflare_record_id' => $recordId,
                    ]);
                    Log::info('Created Cloudflare CNAME.', [
                        'tenant_id' => $this->tenantId,
                        'name' => $recordName,
                    ]);
                } else {
                    Log::error('Failed to create Cloudflare CNAME.', [
                        'tenant_id' => $this->tenantId,
                        'status' => $response->status(),
                    ]);
                }
            } elseif ($this->action === 'update' && $this->cloudflareRecordId) {
                $response = Http::withHeaders($headers)->patch("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$this->cloudflareRecordId}", [
                    'type' => 'CNAME',
                    'name' => $recordName,
                    'content' => $target,
                    'ttl' => 3600,
                    'proxied' => true,
                ]);

                if (! $response->successful()) {
                    Log::error('Failed to update Cloudflare CNAME.', [
                        'tenant_id' => $this->tenantId,
                        'status' => $response->status(),
                    ]);
                }
            } elseif ($this->action === 'delete' && $this->cloudflareRecordId) {
                $response = Http::withHeaders($headers)->delete("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$this->cloudflareRecordId}");

                if (! $response->successful()) {
                    Log::error('Failed to delete Cloudflare CNAME.', [
                        'tenant_id' => $this->tenantId,
                        'status' => $response->status(),
                    ]);
                }
            }
        } catch (Exception $exception) {
            Log::error('Cloudflare DNS job failed.', [
                'tenant_id' => $this->tenantId,
                'action' => $this->action,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function recordName(): ?string
    {
        $baseDomain = (string) config('multitenancy.base_domain', 'iterp.in');
        $domain = strtolower($this->domain);

        if ($baseDomain !== '' && str_ends_with($domain, '.'.$baseDomain)) {
            $subdomain = substr($domain, 0, -1 * (strlen($baseDomain) + 1));

            return $subdomain !== '' ? $subdomain : null;
        }

        return $domain !== '' ? $domain : null;
    }
}
