<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Billing\MonthlyBilling;
use Illuminate\Console\Command;

class GenerateBillingInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:generate-invoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create monthly bills after each completed month of workspace use';

    /**
     * Execute the console command.
     */
    public function handle(MonthlyBilling $billing): int
    {
        Tenant::query()->whereNotNull('billing_started_at')->where('provisioning_status', 'ready')->orderBy('id')->each(function (Tenant $tenant) use ($billing): void {
            $tenant->execute(fn () => $billing->generateDueInvoices($tenant));
        });

        return self::SUCCESS;
    }
}
