<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Field\SecondaryOcrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Multitenancy\Jobs\TenantAware;
use Throwable;

class ExtractSecondaryStatement implements ShouldQueue, TenantAware
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public ?int $tenantId = null;

    public function __construct(public int $sourceId, public ?string $extractionToken = null)
    {
        $this->tenantId = Tenant::current()?->id;
    }

    /**
     * Execute the job.
     */
    public function handle(SecondaryOcrService $service): void
    {
        if (Tenant::current()?->id !== $this->tenantId) {
            throw new \RuntimeException('OCR company context does not match.');
        }
        $service->extract($this->sourceId, $this->extractionToken);
    }

    public function failed(?Throwable $exception): void
    {
        if (Tenant::current()?->id === $this->tenantId && $this->extractionToken !== null) {
            app(SecondaryOcrService::class)->failAttempt($this->sourceId, $this->extractionToken, 'The OCR worker stopped before completion. The source is retained; retry extraction or map it manually.');
        }
    }
}
