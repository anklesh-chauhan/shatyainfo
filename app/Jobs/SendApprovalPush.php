<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Services\Approval\FirebasePushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendApprovalPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param array<string, string> $payload */
    public function __construct(public int $userId, public array $payload) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(FirebasePushService $firebase): void
    {
        PushSubscription::query()->where('user_id', $this->userId)->each(function (PushSubscription $subscription) use ($firebase): void {
            $firebase->send($subscription, $this->payload);
        });
    }
}
