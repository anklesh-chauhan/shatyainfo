<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class BackfillTenantContactDetails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:backfill-contact-details {--domain= : Limit to one company domain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy missing registration emails from existing tenant owner accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Tenant::query()->whereNull('registration_email')->orderBy('id');

        if ($domain = $this->option('domain')) {
            $query->where('domain', (string) $domain);
        }

        $updated = 0;
        $skipped = 0;

        $query->each(function (Tenant $tenant) use (&$updated, &$skipped): void {
            $owner = $tenant->provisioning_owner;
            $email = is_array($owner) ? ($owner['email'] ?? null) : null;

            if (! is_string($email) && $tenant->provisioning_status === 'ready') {
                try {
                    $email = $tenant->execute(fn (): ?string => User::query()->orderBy('id')->value('email'));
                } catch (Throwable $exception) {
                    $this->warn('Could not read owner account for tenant '.$tenant->id.': '.$exception->getMessage());
                }
            }

            $email = is_string($email) ? strtolower(trim($email)) : null;

            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                return;
            }

            if (Tenant::query()->whereRaw('LOWER(TRIM(registration_email)) = ?', [$email])->exists()) {
                $this->warn('Owner email is already registered; skipped tenant '.$tenant->id.'.');
                $skipped++;

                return;
            }

            $tenant->forceFill(['registration_email' => $email])->saveQuietly();
            $updated++;
        });

        $this->info("Updated {$updated} tenant emails; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
