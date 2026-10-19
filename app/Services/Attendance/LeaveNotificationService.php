<?php

namespace App\Services\Attendance;

use App\Models\LeaveApplication;
use App\Models\LeaveNotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class LeaveNotificationService
{
    /**
     * Dispatch notifications based on event
     */
    public function dispatch(
        string $event,
        LeaveApplication $leave,
        array $ruleResult = []
    ): void {

        $notifications = $ruleResult['notifications'] ?? [];

        foreach ($notifications as $notification) {

            if (! is_array($notification)) {
                throw new \LogicException(
                    'Invalid notification rule: expected array, got '.gettype($notification)
                );
            }

            $this->executeNotification(
                $notification,
                $event,
                $leave,
                $ruleResult
            );
        }
    }

    protected function eventMatches(array $conditions, string $event): bool
    {
        return ($conditions['event'] ?? null) === $event;
    }

    protected function executeNotification(
        array $action,
        string $event,
        LeaveApplication $leave,
        array $ruleResult
    ): void {

        // EMAIL
        if ($action['send_email'] ?? false) {
            $this->sendEmail($action, $leave);
        }

        // SMS
        if ($action['send_sms'] ?? false) {
            $this->sendSms($action, $leave);
        }
    }

    protected function sendEmail(array $action, LeaveApplication $leave): void
    {
        $recipients = match ($action['recipient'] ?? null) {
            'employee' => [$leave->employee?->user],
            'manager' => [$leave->employee?->employmentDetail?->reportingManager?->user],
            default => [],
        };

        foreach ($recipients as $user) {
            if (! $user?->email) {
                continue;
            }

            Mail::raw(
                $this->buildEmailBody($action, $leave),
                fn ($msg) => $msg->to($user->email)
                    ->subject('Leave Notification')
            );

            LeaveNotificationLog::create([
                'event' => $action['event'] ?? 'unknown',
                'leave_application_id' => $leave->id,
                'channel' => 'email',
                'recipient_type' => $action['recipient'],
                'recipient_id' => $user->id,
            ]);
        }
    }

    protected function buildEmailBody(array $action, LeaveApplication $leave): string
    {
        $body = "Leave Application Update\n\n";
        $body .= "Employee: {$leave->employee?->full_name}\n";
        $body .= "Leave: {$leave->from_date} to {$leave->to_date}\n";
        $body .= "Approval Status: {$leave->approval_status}\n\n";

        if (($action['email_action_links'] ?? false) && $leave->approval) {

            $step = $leave->approval->currentStep();

            if ($step) {
                $approveUrl = URL::signedRoute(
                    'leave.email.approve',
                    ['step' => $step->id]
                );

                $rejectUrl = URL::signedRoute(
                    'leave.email.reject',
                    ['step' => $step->id]
                );

                $body .= "Approve: {$approveUrl}\n";
                $body .= "Reject: {$rejectUrl}\n";
            }
        }

        return $body;
    }

    protected function sendSms(array $action, LeaveApplication $leave): void
    {
        // Plug any SMS gateway here
        Log::info('SMS sent', [
            'leave_id' => $leave->id,
            'recipient' => $action['recipient'],
        ]);
    }
}
