<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

trait FieldConcurrencyDatabase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        if (config('database.connections.mysql.database') !== 'it_erp_testing') {
            throw new RuntimeException('Refuse concurrency migrations outside the test database.');
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            if (User::query()->getConnection()->getDatabaseName() !== 'it_erp_testing') {
                throw new RuntimeException('Refuse concurrency fixture cleanup outside the test database.');
            }
            $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        });
    }
}

function runFieldWorkers(array $jobs): array
{
    $root = sys_get_temp_dir().'/field-concurrency-'.Str::uuid();
    mkdir($root);
    $connection = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'FIELD_CONCURRENCY' => '1', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'it_erp_testing',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
        'DB_URL' => false, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'MAIL_MAILER' => 'array', 'APP_CONFIG_CACHE' => $root.'/no-config.php'];
    $processes = [];
    try {
        foreach ($jobs as $index => $job) {
            $path = $root.'/'.$index.'.json';
            file_put_contents($path, json_encode([...$job, 'ready' => $root.'/'.$index.'.ready', 'gate' => $root.'/go'], JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, base_path('tests/Feature/field_concurrency_worker.php'), $path], base_path(), $environment, timeout: 120 + (int) ($job['duration_seconds'] ?? 0));
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 60;
        while (count(glob($root.'/*.ready')) !== count($jobs)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Worker failed before barrier: '.$process->getErrorOutput().$process->getOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach the barrier.');
            }
            usleep(10000);
        }
        file_put_contents($root.'/go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
            try {
                $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('Worker result was not JSON: '.substr($process->getOutput(), 0, 1000), previous: $error);
            }
            $results = [...$results, ...$decoded];
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($root.'/*') as $file) {
            unlink($file);
        }
        rmdir($root);
    }
}
