<?php

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\DateFormatService;
use App\Services\OptionalModuleService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'it_erp_testing' || getenv('FIELD_CONCURRENCY') !== '1') {
    throw new RuntimeException('Concurrency workers require the isolated test database.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->instance('request', Request::create('/'));
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (User::query()->getConnection()->getDatabaseName() !== 'it_erp_testing') {
    throw new RuntimeException('Unexpected worker database.');
}
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (($job['mode'] ?? '') === 'company_isolation') {
    require __DIR__.'/company_isolation_support.php';
}
if (($job['mode'] ?? '') === 'company_launch') {
    config(['multitenancy.switch_tenant_tasks' => [], 'database.connections.tenant' => config('database.connections.mysql')]);
    $connection = $app->make('db')->connection('mysql');
    $app->make('db')->connection('tenant')->setPdo($connection->getPdo())->setReadPdo($connection->getPdo());
    $app->make('db')->setDefaultConnection('tenant');
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99997, 'name' => 'Isolated launch acceptance', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    auth()->setUser(User::query()->findOrFail($job['actor_id']));
}
file_put_contents($job['ready'], 'ready');
$deadline = microtime(true) + 60;
while (! file_exists($job['gate'])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
$samples = [];
$send = function (array $spec) use ($job, $kernel, &$samples): array {
    $server = [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
    ];
    if (isset($spec['operation'])) {
        $server['HTTP_IDEMPOTENCY_KEY'] = $spec['operation'];
    }
    $request = Request::create($spec['url'], $spec['method'] ?? 'GET', server: $server,
        content: isset($spec['body']) ? json_encode($spec['body'], JSON_THROW_ON_ERROR) : null);
    $start = microtime(true);
    $response = $kernel->handle($request);
    $end = microtime(true);
    $data = json_decode($response->getContent(), true);
    $samples[] = ['kind' => $spec['kind'], 'start' => $start, 'end' => $end, 'ms' => ($end - $start) * 1000,
        'status' => $response->getStatusCode(), 'bytes' => strlen($response->getContent()),
        'count' => count($data['data'][$spec['kind'] === 'coverage' ? 'customers' : 'entries'] ?? []),
        'visit_id' => $data['data']['id'] ?? null,
        'replayed' => $response->headers->get('Idempotency-Replayed') === 'true'];
    $kernel->terminate($request, $response);
    if (($job['mode'] ?? '') === 'mixed' && ! $response->isSuccessful()) {
        throw new RuntimeException('Mixed request '.$spec['kind'].' failed with '.$response->getStatusCode().': '.($data['message'] ?? 'No API message'));
    }

    return $data ?? [];
};
if (($job['mode'] ?? '') === 'company_isolation') {
    $start = microtime(true);
    foreach (range(1, 10) as $cycle) {
        foreach ($job['companies'] as $company) {
            selectIsolationCompany($company);
            auth()->setUser(User::query()->findOrFail($job['actor_id']));
            $service = app(CompanyOnboardingService::class);
            $expected = $company['name'].'-'.$job['actor_id'];
            if ($service->draft()['organization']['name'] !== $expected
                || app(DateFormatService::class)->dateFormat() !== $company['format']
                || app(OptionalModuleService::class)->capabilities()['enabled']['expenses'] !== $company['expenses']
                || app(CompanyLaunchService::class)->forUser(auth()->user())['company_name'] !== $company['name']) {
                throw new RuntimeException('Cross-company state mismatch.');
            }
            $service->saveDraft(['step' => 1, 'organization' => ['name' => $expected],
                'workflow' => '', 'workflow_version' => 0, 'approval' => []]);
            $connection = app('db')->connection();
            $connection->transaction(function () use ($company): void {
                $organization = Organization::query()->lockForUpdate()->firstOrFail();
                if ($organization->name !== $company['name']) {
                    throw new RuntimeException('Wrong company write target.');
                }
                $metadata = $organization->metadata;
                $metadata['isolation_writes'] = ($metadata['isolation_writes'] ?? 0) + 1;
                $organization->update(['metadata' => $metadata]);
            }, 3);
            Tenant::forgetCurrent();
            if (app('db')->getDefaultConnection() !== 'mysql'
                || config('database.connections.tenant.database') !== null) {
                throw new RuntimeException('Company connection was not reset.');
            }
        }
    }
    $samples[] = ['kind' => 'company_isolation', 'start' => $start, 'end' => microtime(true),
        'status' => 200, 'writes_per_company' => 10];
} elseif (($job['mode'] ?? '') === 'company_launch') {
    $start = microtime(true);
    $errors = [];
    try {
        $app->make(CompanyLaunchService::class)->launch($job['employee_ids'], $job['revision_id']);
        $status = 200;
    } catch (ValidationException $exception) {
        $status = 422;
        $errors = $exception->errors();
    } finally {
        Tenant::forgetCurrent();
    }
    $samples[] = ['kind' => 'company_launch', 'start' => $start, 'end' => microtime(true),
        'status' => $status, 'employee_ids' => $job['employee_ids'], 'errors' => $errors];
} elseif (($job['mode'] ?? '') === 'mixed') {
    $began = microtime(true);
    $until = $began + $job['duration_seconds'];
    $cycle = 0;
    $dcrId = null;
    do {
        $accountId = $job['account_ids'][$cycle % count($job['account_ids'])];
        $target = ['client_call_id' => (string) Str::uuid(), 'company_id' => $accountId, 'date' => $job['date']];
        if ($cycle % 2 === 0 && $cycle < count($job['account_ids'])) {
            $plan = $send(['kind' => 'plan', 'method' => 'POST', 'url' => '/api/v1/flexible-plans',
                'body' => ['client_plan_id' => (string) Str::uuid(), 'expected_version' => 0, 'account_id' => $accountId,
                    'date' => $job['date'], 'status' => 'active', 'notes' => 'Concurrent soak intention']]);
            $target += ['flexible_plan_entry_id' => $plan['data']['id'], 'flexible_plan_version' => $plan['data']['version']];
        } else {
            $target['unplanned_reason'] = 'Synthetic mixed-write acceptance';
        }
        $opened = $send(['kind' => 'open', 'method' => 'POST', 'url' => '/api/v1/visits/direct',
            'operation' => (string) Str::uuid(), 'body' => $target]);
        $dcrId = $opened['data']['sales_dcr_id'];
        $previous = null;
        foreach (['check_in', 'details', 'check_out'] as $index => $action) {
            $event = ['event_id' => (string) Str::uuid(), 'target' => $target, 'action' => $action,
                'captured_at' => now()->subMinutes(3 - $index)->toIso8601String(), 'previous_event_id' => $previous,
                'payload' => $action === 'details' ? ['remarks' => 'Soak original note '.$cycle] : ['latitude' => 22, 'longitude' => 73]];
            $spec = ['kind' => $action, 'method' => 'POST', 'url' => '/api/v1/field-visit-events',
                'operation' => $event['event_id'], 'body' => $event];
            $send($spec);
            if ($action !== 'check_in') {
                $send([...$spec, 'kind' => $action.'_retry']);
                if (! $samples[array_key_last($samples)]['replayed']) {
                    throw new RuntimeException('Duplicate event was not replayed.');
                }
            }
            $previous = $event['event_id'];
        }
        $send(['kind' => 'coverage', 'url' => '/api/v1/field-coverage?month='.$job['month']]);
        $send(['kind' => 'weekly', 'url' => '/api/v1/flexible-plans?start='.$job['week']]);
        $cycle++;
    } while (microtime(true) < $until);
    $submit = ['kind' => 'submit', 'method' => 'POST', 'url' => '/api/v1/dcrs/'.$dcrId.'/submit',
        'operation' => (string) Str::uuid(), 'body' => []];
    $send($submit);
    $send([...$submit, 'kind' => 'submit_retry']);
    if (! $samples[array_key_last($samples)]['replayed']) {
        throw new RuntimeException('DCR submission retry was not replayed.');
    }
    $samples[] = ['kind' => 'mixed_summary', 'user_id' => $job['user_id'], 'cycles' => $cycle,
        'seconds' => microtime(true) - $began, 'peak_memory_bytes' => memory_get_peak_usage(true), 'dcr_id' => $dcrId];
} else {
    foreach ($job['requests'] as $spec) {
        $send($spec);
    }
}
echo json_encode($samples, JSON_THROW_ON_ERROR);
