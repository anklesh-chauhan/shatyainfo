<?php

namespace App\Services\Approval;

use App\Enums\ApprovalStatus;
use App\Jobs\SendApprovalPush;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalActionedNotification;

class ApprovalNotificationService
{
    public function pending(Approval $approval): void
    {
        $step = $approval->currentStep();
        if ($step?->approver) {
            $this->send($step->approver, $approval, 'pending', (int) $step->id);
        }
    }

    public function completed(Approval $approval, ApprovalStatus $status): void
    {
        if ($approval->requestedBy) {
            $this->send($approval->requestedBy, $approval, $status->value);
        }
    }

    private function send(User $user, Approval $approval, string $status, ?int $stepId = null): void
    {
        $notification = new ApprovalActionedNotification($approval, $status, $stepId);
        $user->notifyNow($notification);
        if (config('services.firebase.enabled')) {
            SendApprovalPush::dispatch((int) $user->id, [...$notification->payload(), 'user_id' => (string) $user->id])
                ->onConnection(config('services.firebase.queue_connection'))
                ->onQueue(config('services.firebase.queue'))
                ->afterCommit();
        }
    }
}
