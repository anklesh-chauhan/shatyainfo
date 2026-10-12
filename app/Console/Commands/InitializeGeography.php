<?php

namespace App\Console\Commands;

use App\Support\Tenancy\GeographicReference;
use Illuminate\Console\Command;

class InitializeGeography extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geography:init {--validate : Validate the existing reference dataset without importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize or validate the geographic master database once for tenant provisioning.';

    /**
     * Execute the console command.
     */
    public function handle(GeographicReference $reference): int
    {
        try {
            $counts = $this->option('validate') ? $reference->validate() : $reference->initialize();
            foreach ($counts as $table => $count) {
                $this->line($table.': '.$count);
            }
            $this->components->info('Geographic reference data is ready.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
