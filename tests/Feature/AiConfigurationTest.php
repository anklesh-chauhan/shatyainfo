<?php

use App\Filament\Pages\AiConfiguration;
use App\Models\IntegrationSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Services\SalespersonAi\AiBriefingService;
use App\Services\SalespersonAi\AiSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'salesperson_ai.enabled' => false,
        'salesperson_ai.allow_standalone' => false,
        'salesperson_ai.allowed_tenants' => [],
        'salesperson_ai.providers.openai' => ['api_key' => null, 'model' => null],
        'salesperson_ai.providers.anthropic' => ['api_key' => null, 'model' => null],
        'salesperson_ai.providers.ollama' => ['base_url' => 'http://127.0.0.1:11434', 'model' => null],
    ]);
    Cache::flush();
    Http::preventStrayRequests();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('administration_admin', 'web'));
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->settings = app(AiSettingsService::class);
});

it('renders provider settings only for administrators', function () {
    livewire(AiConfiguration::class)->assertSuccessful()
        ->assertSee('OpenAI')->assertSee('Claude')->assertSee('Local model (Ollama)')
        ->assertSee('Save configuration')->assertSee('Test connection');
    $this->actingAs(User::factory()->create());
    expect(AiConfiguration::canAccess())->toBeFalse();
    livewire(AiConfiguration::class)->assertForbidden();
    Http::assertNothingSent();
});

it('saves encrypted credentials and never fills saved secrets into the browser', function () {
    livewire(AiConfiguration::class)->fillForm([
        'enabled' => true,
        'provider' => 'openai',
        'openai_model' => 'test-model',
        'openai_api_key' => 'private-api-key',
        'daily_requests' => 250,
    ])->call('save')->assertHasNoFormErrors()->assertNotified('AI configuration saved')
        ->assertSet('data.openai_api_key', '')
        ->assertDontSee('private-api-key');
    $record = IntegrationSetting::query()->where('provider', $this->settings->storageKey())->sole();
    expect($record->getRawOriginal('credentials'))->not->toContain('private-api-key')
        ->and($record->credentials['providers']['openai']['api_key'])->toBe('private-api-key')
        ->and($this->settings->configuration()['daily_requests'])->toBe(250)
        ->and($this->settings->configuration()['enabled'])->toBeTrue();
    livewire(AiConfiguration::class)->assertSet('data.openai_api_key', '')
        ->assertSet('data.openai_model', 'test-model')
        ->assertSee('A key is configured.')
        ->assertDontSee('private-api-key');
    Http::assertNothingSent();
});

it('preserves blank keys and supports explicit removal without environment fallback', function () {
    $state = $this->settings->formState();
    $this->settings->save([...$state, 'openai_api_key' => 'keep-me', 'openai_model' => 'test-model']);
    livewire(AiConfiguration::class)->fillForm(['daily_requests' => 500])->call('save')->assertHasNoFormErrors();
    expect($this->settings->configuration()['providers']['openai']['api_key'])->toBe('keep-me');
    config(['salesperson_ai.allow_standalone' => true, 'salesperson_ai.providers.openai.api_key' => 'env-key']);
    livewire(AiConfiguration::class)->fillForm(['clear_openai_key' => true])->call('save')->assertHasNoFormErrors();
    expect($this->settings->configuration()['providers']['openai']['api_key'])->toBeNull();
});

it('validates credentials and limits before enabling the selected provider', function () {
    livewire(AiConfiguration::class)->fillForm([
        'enabled' => true, 'provider' => 'openai', 'openai_model' => 'test-model',
    ])->call('save')->assertHasFormErrors(['openai_api_key']);
    livewire(AiConfiguration::class)->fillForm([
        'enabled' => true, 'provider' => 'ollama',
    ])->call('save')->assertHasFormErrors(['ollama_model']);
    livewire(AiConfiguration::class)->fillForm(['daily_requests' => -1])
        ->call('save')->assertHasFormErrors(['daily_requests']);
    expect(IntegrationSetting::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('applies GUI provider and limit settings to briefing requests', function () {
    $this->settings->save([
        ...$this->settings->formState(),
        'enabled' => true,
        'provider' => 'anthropic',
        'anthropic_model' => 'claude-test',
        'anthropic_api_key' => 'claude-key',
        'daily_requests' => 1,
    ]);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => '{"fact_ids":["visit:11"]}']],
    ])]);
    $visit = (new Visit)->forceFill(['id' => 5, 'employee_id' => $this->admin->id]);
    $briefing = ['summary' => 'Original.', 'last_visits' => [
        ['id' => 11, 'visit_date' => '2026-10-19', 'remarks' => 'Recorded concern.'],
    ]];
    $result = app(AiBriefingService::class)->enrich($visit, $briefing);
    expect($result['ai']['status'])->toBe('assisted');
    Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'claude-key') && $request['model'] === 'claude-test');
    $briefing['last_visits'][0]['remarks'] = 'Changed concern.';
    expect(app(AiBriefingService::class)->enrich($visit, $briefing)['ai']['status'])->toBe('limited');
    $this->settings->save([...$this->settings->formState(), 'enabled' => false]);
    config(['salesperson_ai.enabled' => true, 'salesperson_ai.allow_standalone' => true]);
    expect(app(AiBriefingService::class)->enrich($visit, $briefing)['ai']['status'])->toBe('disabled');
    Http::assertSentCount(1);
});

it('tests unsaved Ollama settings with sample text without saving or sending credentials', function () {
    Http::fake(['127.0.0.1:11434/*' => Http::response([
        'done' => true, 'done_reason' => 'stop',
        'message' => ['content' => '{"fact_ids":["connection_test"]}'],
        'prompt_eval_count' => 25, 'eval_count' => 8,
    ])]);
    livewire(AiConfiguration::class)->fillForm([
        'provider' => 'ollama', 'ollama_model' => 'local-test:8b', 'timeout_seconds' => 90,
    ])->call('testConnection')->assertHasNoFormErrors()->assertNotified('Connection successful');
    expect(IntegrationSetting::query()->count())->toBe(0);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:11434/api/chat'
        && $request['model'] === 'local-test:8b'
        && $request['stream'] === false
        && $request['format']['properties']['fact_ids']['items']['enum'] === ['connection_test']
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('x-api-key'));
});

it('reports failed tests without exposing provider error bodies', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'sensitive-provider-detail'], 401)]);
    livewire(AiConfiguration::class)->fillForm([
        'provider' => 'openai', 'openai_model' => 'test-model', 'openai_api_key' => 'test-key',
    ])->call('testConnection')->assertNotified('Connection could not be verified')
        ->assertDontSee('sensitive-provider-detail');
});

it('rate limits connection tests', function () {
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);
    $page = livewire(AiConfiguration::class)->fillForm([
        'provider' => 'openai', 'openai_model' => 'test-model', 'openai_api_key' => 'test-key',
    ]);
    foreach (range(1, 4) as $attempt) {
        $page->call('testConnection');
    }
    $page->assertNotified('Please wait before testing again');
    Http::assertSentCount(3);
});

it('rejects unsafe local endpoints before making requests', function (string $url) {
    livewire(AiConfiguration::class)->fillForm([
        'provider' => 'ollama', 'ollama_model' => 'local-model', 'ollama_base_url' => $url,
    ])->call('testConnection')->assertHasFormErrors(['ollama_base_url']);
    Http::assertNothingSent();
})->with([
    'http://169.254.169.254',
    'http://example.com',
    'http://127.0.0.1:11434/admin',
    'http://user:password@127.0.0.1:11434',
    'http://127.0.0.1:11434?token=secret',
    'file:///etc/passwd',
]);

it('keeps company configurations separate without changing global configuration', function () {
    foreach ([21 => 'company-one-key', 22 => 'company-two-key'] as $id => $key) {
        app()->instance('currentTenant', (new Tenant)->forceFill(['id' => $id]));
        $this->settings->save([
            ...$this->settings->formState(),
            'enabled' => true, 'provider' => 'openai', 'openai_model' => 'test-model', 'openai_api_key' => $key,
        ]);
    }
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 21]));
    expect($this->settings->configuration()['providers']['openai']['api_key'])->toBe('company-one-key');
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 23]));
    expect($this->settings->configuration()['enabled'])->toBeFalse()
        ->and($this->settings->configuration()['providers']['openai']['api_key'])->toBeNull()
        ->and(config('salesperson_ai.enabled'))->toBeFalse();
});

it('reauthorizes save and connection test actions after permissions change', function (string $action) {
    $page = livewire(AiConfiguration::class);
    $this->admin->syncRoles([]);
    $page->call($action)->assertForbidden();
    Http::assertNothingSent();
    expect(IntegrationSetting::query()->count())->toBe(0);
})->with(['save', 'testConnection']);

it('rejects actions after the company context changes', function () {
    $page = livewire(AiConfiguration::class);
    app()->instance('currentTenant', (new Tenant)->forceFill(['id' => 99]));
    $page->call('save')->assertForbidden();
    expect(IntegrationSetting::query()->count())->toBe(0);
});
