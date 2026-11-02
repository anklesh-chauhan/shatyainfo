<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

class MonthlyBilling
{
    public function generateDueInvoices(Tenant $tenant): void
    {
        if ($tenant->provisioning_status !== 'ready' || ! $tenant->billing_started_at) {
            return;
        }

        $now = CarbonImmutable::now();

        for ($month = 1; ; $month++) {
            $periodStart = $this->duePeriodStart($tenant, $month, $now);

            if ($periodStart === null) {
                break;
            }

            if (! $this->shouldBillPeriod($periodStart, $tenant->billing_cancelled_at ? CarbonImmutable::instance($tenant->billing_cancelled_at) : null)) {
                break;
            }

            if (BillingInvoice::query()->where('tenant_id', $tenant->id)->whereDate('period_start', $periodStart->toDateString())->exists()) {
                continue;
            }

            $licensedUsers = max(10, User::query()->count());

            BillingInvoice::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'period_start' => $periodStart->toDateString()],
                ['licensed_users' => $licensedUsers, 'amount_paise' => $this->amountPaise($licensedUsers), 'status' => 'pending'],
            );
        }
    }

    public function duePeriodStart(Tenant $tenant, int $month, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! $tenant->billing_started_at || $month < 1) {
            return null;
        }

        $startedAt = CarbonImmutable::instance($tenant->billing_started_at);

        if ($tenant->billing_trial_ends_at) {
            $periodStart = CarbonImmutable::instance($tenant->billing_trial_ends_at)->addMonthsNoOverflow($month - 1);

            return $periodStart->lessThanOrEqualTo($now) ? $periodStart : null;
        }

        $periodStart = $startedAt->addMonthsNoOverflow($month - 1);

        return $startedAt->addMonthsNoOverflow($month)->lessThanOrEqualTo($now) ? $periodStart : null;
    }

    public function amountPaise(int $licensedUsers): int
    {
        return max(10, $licensedUsers) * 5900;
    }

    public function shouldBillPeriod(CarbonImmutable $periodStart, ?CarbonImmutable $cancelledAt): bool
    {
        return $cancelledAt === null || $periodStart->lessThan($cancelledAt);
    }
}
