<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
if (($job['mode'] ?? '') === 'mixed') {
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
