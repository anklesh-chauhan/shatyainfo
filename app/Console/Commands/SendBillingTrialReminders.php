<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendBillingTrialReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:send-trial-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email company owners before their free trial ends';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failed = false;

        Tenant::query()
            ->where('provisioning_status', 'ready')
            ->where('master_setup_status', 'ready')
            ->whereNull('billing_cancelled_at')
            ->whereNull('billing_trial_reminder_sent_at')
            ->whereNotNull('registration_email')
            ->where('billing_trial_ends_at', '>', now())
            ->where('billing_trial_ends_at', '<=', now()->addDays(7))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use (&$failed): void {
                try {
                    Mail::raw(
                        "Your 30-day Shatya Info trial for {$tenant->name} ends on {$tenant->billing_trial_ends_at->format('d M Y')}. All features remain available during the trial. If you want to continue, open Billing in your company workspace and pay for the next month through Razorpay. Plans start at ₹590 per month for 10 user licenses, including tax. You can cancel from Billing before the trial ends and owe nothing. No payment is charged automatically.",
                        function ($message) use ($tenant): void {
                            $message->to($tenant->registration_email)->subject('Your Shatya Info trial ends soon');
                        },
                    );

                    $tenant->forceFill(['billing_trial_reminder_sent_at' => now()])->saveQuietly();
                } catch (Throwable $exception) {
                    $failed = true;
                    report($exception);
                    $this->error("Could not send trial reminder for company {$tenant->id}.");
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
