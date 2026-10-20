<?php

use App\Models\Tenant;
use App\Models\Visit;
use App\Services\SalespersonAi\AiBriefingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'salesperson_ai.enabled' => true,
        'salesperson_ai.allow_standalone' => true,
        'salesperson_ai.provider' => 'openai',
        'salesperson_ai.providers.openai' => ['api_key' => 'test-secret', 'model' => 'test-model'],
        'salesperson_ai.providers.anthropic' => ['api_key' => 'claude-secret', 'model' => 'claude-test-model'],
    ]);
    Cache::flush();
    Http::preventStrayRequests();
    $this->visit = (new Visit)->forceFill(['id' => 5, 'employee_id' => 7]);
    $this->briefing = [
        'account' => ['id' => 6, 'name' => 'Private Doctor Name'],
        'summary' => 'Original summary.',
        'samples' => ['summary' => 'Original SGIP limits.', 'suggestions' => []],
        'last_visits' => [
            ['id' => 11, 'visit_date' => '2026-10-19', 'outcome' => null, 'remarks' => 'Asked for a brochure.'],
        ],
        'open_follow_ups' => [
            ['id' => 12, 'next_follow_up_date' => '2026-10-18', 'overdue' => true, 'interaction' => 'Bring the approved brochure.'],
        ],
    ];
    $this->providerResponse = [
        'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => '{"fact_ids":["follow_up:12","visit:11"]}'],
        ]]],
        'usage' => ['input_tokens' => 150, 'output_tokens' => 20],
    ];
});

it('selects existing facts using OpenAI and caches only the references', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->providerResponse)]);
    Log::spy();
    $service = app(AiBriefingService::class);
    $result = $service->enrich($this->visit, $this->briefing);

    expect($result['ai']['status'])->toBe('assisted')
        ->and($result['ai']['source_ids'])->toBe(['follow_up:12', 'visit:11'])
        ->and($result['ai_highlights'][0])->toContain('Overdue follow-up', 'Bring the approved brochure.')
        ->and($result['summary'])->toBe('Original summary.')
        ->and($result['samples'])->toBe($this->briefing['samples']);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer test-secret')
        && $request['store'] === false
        && $request['model'] === 'test-model'
        && ! str_contains($request->body(), 'Private Doctor Name'));
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('cached');
    Http::assertSentCount(1);
    Log::shouldHaveReceived('info')->with('salesperson_ai.briefing', Mockery::on(
        fn (array $context): bool => ($context['input_tokens'] ?? null) === 150
            && ! str_contains(json_encode($context), 'brochure')
            && ! str_contains(json_encode($context), 'test-secret'),
    ))->once();
});

it('uses the Claude Messages API with the same validated result contract', function () {
    config(['salesperson_ai.provider' => 'anthropic']);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => '{"fact_ids":["visit:11"]}']],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
    ])]);
    $result = app(AiBriefingService::class)->enrich($this->visit, $this->briefing);
    expect($result['ai']['status'])->toBe('assisted')
        ->and($result['ai_highlights'])->toBe(['Recorded visit on 2026-10-19. Recorded remarks: Asked for a brochure.']);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key', 'claude-secret')
        && $request->hasHeader('anthropic-version', '2023-06-01')
        && $request['model'] === 'claude-test-model');
});

it('fails closed when external processing is disabled or unconfigured', function (string $setting, mixed $value, string $status) {
    config([$setting => $value]);
    Http::fake();
    $result = app(AiBriefingService::class)->enrich($this->visit, $this->briefing);
    expect($result['ai']['status'])->toBe($status)
        ->and($result['summary'])->toBe('Original summary.');
    Http::assertNothingSent();
})->with([
    ['salesperson_ai.enabled', false, 'disabled'],
    ['salesperson_ai.allow_standalone', false, 'disabled'],
    ['salesperson_ai.providers.openai.api_key', '', 'not_configured'],
    ['salesperson_ai.providers.openai.model', '', 'not_configured'],
    ['salesperson_ai.provider', 'unknown', 'not_configured'],
]);

it('rejects fabricated references and malformed model output', function (string $text) {
    $this->providerResponse['output'][0]['content'][0]['text'] = $text;
    Http::fake(['api.openai.com/*' => Http::response($this->providerResponse)]);
    $result = app(AiBriefingService::class)->enrich($this->visit, $this->briefing);
    expect($result['ai']['status'])->toBe('unavailable')
        ->and($result)->not->toHaveKey('ai_highlights')
        ->and($result['summary'])->toBe('Original summary.');
})->with([
    '{"fact_ids":["visit:999"]}',
    '{"fact_ids":["visit:11","visit:11"]}',
    '{"fact_ids":[]}',
    '{"fact_ids":[11]}',
    '{"fact_ids":["visit:11"],"claim":"Invented claim"}',
    'not json',
]);

it('falls back on provider errors without sending data to another provider', function (int $status) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'private error'], $status)]);
    $result = app(AiBriefingService::class)->enrich($this->visit, $this->briefing);
    expect($result['ai']['status'])->toBe('unavailable')
        ->and($result['summary'])->toBe('Original summary.');
    Http::assertSentCount(1);
})->with([401, 429, 500]);

it('rejects truncated results and connection failures', function () {
    $this->providerResponse['status'] = 'incomplete';
    Http::fake(['api.openai.com/*' => Http::response($this->providerResponse)]);
    expect(app(AiBriefingService::class)->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('unavailable');
    Http::fake(['api.openai.com/*' => Http::failedConnection()]);
    expect(app(AiBriefingService::class)->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('unavailable');
});

it('enforces company daily limits including failed attempts', function () {
    config(['salesperson_ai.daily_requests' => 1]);
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);
    $service = app(AiBriefingService::class);
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('unavailable');
    $this->visit->employee_id = 8;
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('limited');
    Http::assertSentCount(1);
});

it('enforces per-user limits without blocking another user', function () {
    config(['salesperson_ai.user_requests_per_minute' => 1]);
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);
    $service = app(AiBriefingService::class);
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('unavailable');
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('limited');
    $this->visit->employee_id = 8;
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('unavailable');
    Http::assertSentCount(2);
});

it('requires tenant opt-in and isolates cached results and budgets between tenants', function () {
    config(['salesperson_ai.allowed_tenants' => ['21', '22'], 'salesperson_ai.daily_requests' => 1]);
    Http::fake(['api.openai.com/*' => Http::response($this->providerResponse)]);
    $service = app(AiBriefingService::class);
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 20]));
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('disabled');
    Http::assertNothingSent();
    foreach ([21, 22] as $id) {
        app()->instance('currentTenant', (new Tenant)->forceFill(['id' => $id]));
        expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('assisted');
    }
    Http::assertSentCount(2);
});

it('skips empty or oversized context without contacting providers', function () {
    Http::fake();
    $service = app(AiBriefingService::class);
    expect($service->enrich($this->visit, ['summary' => 'Empty'])['ai']['status'])->toBe('no_context');
    $this->briefing['last_visits'][0]['remarks'] = str_repeat('a', 13000);
    expect($service->enrich($this->visit, $this->briefing)['ai']['status'])->toBe('context_too_large');
    Http::assertNothingSent();
});
