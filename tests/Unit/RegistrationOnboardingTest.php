<?php

use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Support\Tenancy\CompanyDomain;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Spatie\Multitenancy\Tasks\PrefixCacheTask;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'session.driver' => 'array', 'cache.default' => 'array', 'cache.stores.registration' => ['driver' => 'array']]);
    DB::purge('mysql');
    Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('registration_mobile', 16)->nullable()->unique();
        $table->string('domain');
        $table->string('database');
        $table->string('provisioning_status');
        $table->text('provisioning_owner')->nullable();
        $table->text('onboarding_data')->nullable();
        $table->string('onboarding_status')->nullable();
        $table->timestamps();
    });
    Queue::fake();
    $this->tenant = Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill(['name' => 'Northwind', 'domain' => 'northwind.test', 'database' => 'not_created_yet', 'provisioning_status' => 'pending'])->save();

        return $tenant;
    });
});

test('pending companies show progress without onboarding forms', function () {
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $this->get($url)->assertOk()->assertSee('Registration progress')->assertDontSee('Organization information')->assertDontSee('Employee information');
});

test('registration onboarding is unavailable before the company database exists', function () {
    $this->post('/register/'.$this->tenant->id.'/onboarding', ['organization' => ['name' => 'Northwind']])->assertNotFound();
    expect($this->tenant->fresh()->onboarding_data)->toBeNull();
    Queue::assertNothingPushed();
});

test('registration onboarding rejects unsigned access', function () {
    $this->post('/register/'.$this->tenant->id.'/onboarding', ['skip' => '1'])->assertNotFound();
    expect($this->tenant->fresh()->onboarding_data)->toBeNull();
});

test('onboarding cannot be skipped through registration', function () {
    $this->post('/register/'.$this->tenant->id.'/onboarding', ['skip' => '1'])->assertNotFound();
    Queue::assertNothingPushed();
});

test('employee information cannot be saved through registration', function () {
    $this->post('/register/'.$this->tenant->id.'/onboarding', ['employees' => [['employee_id' => '001']]])->assertNotFound();
    expect($this->tenant->fresh()->onboarding_data)->toBeNull();
});

test('captcha is required and invalid answers cannot register a company', function () {
    $this->post('/register', ['mobile_number' => '9876543210', 'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-password-1', 'password_confirmation' => 'long-password-1'])->assertSessionHasErrors('captcha');
    expect(Tenant::query()->count())->toBe(1);
});

test('captcha returns a noncached image and stores an expiring challenge', function () {
    $this->get(route('signup.captcha'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertSessionHas('registration_captcha');
});

test('registration waits for email verification without dispatching provisioning', function () {
    Mail::fake();
    $this->withSession(['registration_captcha' => ['answer' => 'ABC234', 'expires' => time() + 600]])->post('/register', [
        'mobile_number' => '9876543210', 'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Long-password-1', 'password_confirmation' => 'Long-password-1', 'captcha' => 'abc234',
    ])->assertRedirect();
    expect(Tenant::query()->where('name', 'Example')->first()->provisioning_status)->toBe('awaiting_verification');
    expect(Tenant::query()->where('name', 'Example')->first()->registration_mobile)->toBe('+919876543210');
    expect(Cache::store('registration')->get('registration.verify.'.Tenant::query()->where('name', 'Example')->first()->id))->toHaveKeys(['hash', 'attempts', 'expires']);
    $statusUrl = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => Tenant::query()->where('name', 'Example')->first()]);
    $this->get($statusUrl)->assertOk()->assertSee('Email verification code')->assertSee('autocomplete="one-time-code"', false);
    Queue::assertNothingPushed();
});

test('email code verification queues provisioning once and rejects replay', function () {
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification', 'provisioning_owner' => ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-password-1']])->saveQuietly();
    $code = '123456';
    $key = 'registration.verify.'.$this->tenant->id;
    Cache::store('registration')->put($key, ['hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'attempts' => 0, 'expires' => now()->addMinutes(10)->getTimestamp()], now()->addMinutes(10));
    $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['tenant' => $this->tenant]);
    $this->post($url, ['code' => $code])->assertRedirect()->assertSessionHas('verified_registration', $this->tenant->id);
    expect($this->tenant->fresh()->provisioning_status)->toBe('pending')->and($this->tenant->fresh()->provisioning_owner['email_verified_at'])->not->toBeNull();
    Queue::assertPushed(ProvisionTenant::class, 1);
    expect(Cache::store('registration')->get($key))->toBeNull();
    $this->post($url, ['code' => $code])->assertGone();
});

test('email code rejects wrong attempts and locks after five tries', function () {
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification', 'provisioning_owner' => ['email' => 'owner@example.test']])->saveQuietly();
    $key = 'registration.verify.'.$this->tenant->id;
    $code = '123456';
    Cache::store('registration')->put($key, ['hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'attempts' => 0, 'expires' => now()->addMinutes(10)->getTimestamp()], now()->addMinutes(10));
    $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['tenant' => $this->tenant]);
    $this->post('/register/'.$this->tenant->id.'/verify', ['code' => $code])->assertForbidden();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post($url, ['code' => '000000'])->assertSessionHasErrors('code');
    }
    expect(Cache::store('registration')->get($key))->toBeNull()
        ->and($this->tenant->fresh()->provisioning_status)->toBe('awaiting_verification');
    Queue::assertNothingPushed();
    $this->post($url, ['code' => $code])->assertSessionHasErrors('code');
});

test('an expired email code cannot start provisioning', function () {
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification', 'provisioning_owner' => ['email' => 'owner@example.test']])->saveQuietly();
    $key = 'registration.verify.'.$this->tenant->id;
    Cache::store('registration')->put($key, ['hash' => hash_hmac('sha256', '123456', (string) config('app.key')), 'attempts' => 0, 'expires' => now()->addMinutes(10)->getTimestamp()], now()->addMinutes(10));
    $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['tenant' => $this->tenant]);
    $this->travel(11)->minutes();
    $this->post($url, ['code' => '123456'])->assertSessionHasErrors('code');
    expect($this->tenant->fresh()->provisioning_status)->toBe('awaiting_verification');
    Queue::assertNothingPushed();
});

test('resending invalidates the previous email code', function () {
    Mail::fake();
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification', 'provisioning_owner' => ['email' => 'owner@example.test']])->saveQuietly();
    $key = 'registration.verify.'.$this->tenant->id;
    Cache::store('registration')->put($key, ['hash' => hash_hmac('sha256', '000000', (string) config('app.key')), 'attempts' => 0, 'expires' => now()->addMinutes(10)->getTimestamp()], now()->addMinutes(10));
    $url = URL::temporarySignedRoute('signup.resend', now()->addHour(), ['tenant' => $this->tenant]);
    $this->post($url)->assertRedirect();
    expect(Cache::store('registration')->get($key)['hash'])->not->toBe(hash_hmac('sha256', '000000', (string) config('app.key')));
    $verifyUrl = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['tenant' => $this->tenant]);
    $this->post($verifyUrl, ['code' => '000000'])->assertSessionHasErrors('code');
    Queue::assertNothingPushed();
});

test('a signed progress URL alone cannot automatically sign in', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $this->getJson($url)->assertOk()->assertJsonPath('redirect_url', null);
    $this->get($url)->assertRedirect(CompanyDomain::loginUrl((string) $this->tenant->domain));
});

test('only a verified registration session receives a company handoff', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $response = $this->withSession(['verified_registration' => $this->tenant->id, 'verified_registration_email' => 'owner@example.test'])->getJson($url)->assertOk();
    expect($response->json('redirect_url'))->toContain('/registration/complete/');
    $redirect = $this->withSession(['verified_registration' => $this->tenant->id, 'verified_registration_email' => 'owner@example.test'])->get($url);
    $redirect->assertRedirect();
    expect($redirect->headers->get('Location'))->toContain('/registration/complete/');
});

test('an expired company handoff is replaced for the verified registration session', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $response = $this->withSession([
        'verified_registration' => $this->tenant->id,
        'verified_registration_email' => 'owner@example.test',
        'registration_login_token' => str_repeat('c', 64),
    ])->getJson($url)->assertOk();
    expect($response->json('redirect_url'))->toContain('/registration/complete/')->not->toContain(str_repeat('c', 64));
});

test('company handoff signs in only the verified owner and cannot be replayed', function () {
    config(['multitenancy.switch_tenant_tasks' => [], 'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('tenant');
    Schema::connection('tenant')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    DB::connection('tenant')->table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'hashed', 'email_verified_at' => now()]);
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $token = str_repeat('b', 64);
    Cache::store('registration')->put('registration.login.'.hash('sha256', $token), ['tenant_id' => $this->tenant->id, 'email' => 'owner@example.test'], now()->addMinutes(5));
    $url = 'http://northwind.test/registration/complete/'.$token;
    $this->get($url)->assertRedirect(CompanyDomain::url((string) $this->tenant->domain, '/admin'))->assertSessionHas(Auth::guard('web')->getName(), 1);
    $this->get($url)->assertForbidden();
});

test('registration form renders its required captcha and refresh button', function () {
    $this->get(route('signup.create'))->assertOk()
        ->assertSee('name="captcha"', false)
        ->assertSee('Refresh CAPTCHA')
        ->assertSee('One lowercase letter')
        ->assertSee('One uppercase letter')
        ->assertSee('id="slug-status"', false);
});

test('cloudflare turnstile replaces the image captcha when it is configured', function () {
    config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret' => 'secret-key']);
    $this->get(route('signup.create'))->assertOk()
        ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
        ->assertSee('site-key', false)
        ->assertDontSee('Refresh CAPTCHA');
});

test('cloudflare turnstile must pass before a company is created', function () {
    config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret' => 'secret-key']);
    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false]),
    ]);
    $this->post('/register', [
        'mobile_number' => '9876543210', 'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test',
        'password' => 'Long-password-1', 'password_confirmation' => 'Long-password-1', 'cf-turnstile-response' => 'token',
    ])->assertSessionHasErrors('cf-turnstile-response');
    expect(Tenant::query()->where('name', 'Example')->exists())->toBeFalse();
});

test('a passing cloudflare turnstile allows company registration', function () {
    Mail::fake();
    config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret' => 'secret-key']);
    Http::fake([
        'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true]),
    ]);
    $this->post('/register', [
        'mobile_number' => '9876543210', 'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test',
        'password' => 'Long-password-1', 'password_confirmation' => 'Long-password-1', 'cf-turnstile-response' => 'token',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(Tenant::query()->where('name', 'Example')->exists())->toBeTrue();
});

test('company address availability is reported before registration', function () {
    $this->getJson(route('signup.address', ['slug' => 'ab']))->assertOk()->assertJson(['available' => false, 'status' => 'invalid']);
    $this->getJson(route('signup.address', ['slug' => 'admin']))->assertJsonPath('status', 'reserved')->assertJsonPath('message', 'That address is reserved.');
    $this->tenant->forceFill(['domain' => CompanyDomain::fromSlug('taken')])->saveQuietly();
    $this->getJson(route('signup.address', ['slug' => 'taken']))->assertJsonPath('available', false)->assertJsonPath('status', 'taken');
    $this->getJson(route('signup.address', ['slug' => 'Fresh-Co']))->assertJsonPath('available', true)->assertJsonPath('domain', CompanyDomain::fromSlug('fresh-co'));
});

test('registration rejects a password without both letter cases', function () {
    $this->withSession(['registration_captcha' => ['answer' => 'ABC234', 'expires' => time() + 600]])->post('/register', [
        'mobile_number' => '9876543210', 'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test',
        'password' => 'long-password-1', 'password_confirmation' => 'long-password-1', 'captcha' => 'abc234',
    ])->assertSessionHasErrors('password');
    expect(Tenant::query()->where('name', 'Example')->exists())->toBeFalse();
});

test('verified owners can enter before masters finish even when tenant cache prefixes change', function () {
    config([
        'multitenancy.switch_tenant_tasks' => [PrefixCacheTask::class],
        'cache.default' => 'database',
        'cache.prefix' => 'landlord:',
        'cache.stores.database' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'cache', 'lock_connection' => 'mysql', 'lock_table' => 'cache_locks'],
        'cache.stores.registration' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'cache', 'lock_connection' => 'mysql', 'lock_table' => 'cache_locks', 'prefix' => 'registration:'],
        'database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    Cache::forgetDriver('registration');
    Schema::connection('mysql')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });
    Schema::connection('mysql')->create('cache_locks', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
    Schema::connection('mysql')->table('tenants', function (Blueprint $table): void {
        $table->string('master_setup_status')->default('pending');
    });
    DB::purge('tenant');
    Schema::connection('tenant')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    DB::connection('tenant')->table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'hashed', 'email_verified_at' => now()]);
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $statusUrl = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $response = $this->withSession(['verified_registration' => $this->tenant->id, 'verified_registration_email' => 'owner@example.test'])->getJson($statusUrl)->assertOk();
    $url = $response->json('redirect_url');
    expect($url)->toContain('/registration/complete/');
    Cache::store('registration')->flush();
    $this->get($url)->assertRedirect(CompanyDomain::url((string) $this->tenant->domain, '/admin'))->assertSessionHas(Auth::guard('web')->getName(), 1);
    expect($this->tenant->fresh()->master_setup_status)->toBe('pending');
    $this->get($url)->assertForbidden();
    $prefixedToken = str_repeat('d', 64);
    $logicalKey = 'registration.login.'.hash('sha256', $prefixedToken);
    DB::connection('mysql')->table('cache')->insert([
        'key' => 'tenant_id_'.$this->tenant->id.$logicalKey,
        'value' => serialize(['tenant_id' => $this->tenant->id, 'email' => 'owner@example.test']),
        'expiration' => now()->addMinutes(10)->getTimestamp(),
    ]);
    $prefixedUrl = 'http://northwind.test/registration/complete/'.$prefixedToken;
    $this->get($prefixedUrl)->assertRedirect(CompanyDomain::url((string) $this->tenant->domain, '/admin'));
    $this->get($prefixedUrl)->assertForbidden();
});

test('public company addresses redirect over https', function () {
    config(['app.url' => 'http://shatyainfo.com']);

    expect(CompanyDomain::loginUrl('anklesh.shatyainfo.com'))->toBe('https://anklesh.shatyainfo.com/admin/login')
        ->and(CompanyDomain::url('anklesh.shatyainfo.com', '/registration/complete/token'))->toBe('https://anklesh.shatyainfo.com/registration/complete/token');

    config(['app.url' => 'http://it-erp.test']);

    expect(CompanyDomain::loginUrl('anklesh.it-erp.test'))->toBe('http://anklesh.it-erp.test/admin/login');
});

test('registration polling exposes provisioning percentage and current step', function () {
    Schema::connection('mysql')->table('tenants', function (Blueprint $table): void {
        $table->unsignedTinyInteger('provisioning_progress')->nullable();
        $table->string('provisioning_step')->nullable();
    });
    $this->tenant->forceFill(['provisioning_status' => 'provisioning', 'provisioning_progress' => 42, 'provisioning_step' => 'Creating sign-in tables (2 of 5)'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $this->getJson($url)->assertOk()->assertJsonPath('progress', 42)->assertJsonPath('step', 'Creating sign-in tables (2 of 5)');
    $this->get($url)->assertOk()->assertSee('42% complete');
});
