<?php

use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'session.driver' => 'array', 'cache.default' => 'array']);
    DB::purge('mysql');
    Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
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
    $this->post('/register', ['company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-password-1', 'password_confirmation' => 'long-password-1'])->assertSessionHasErrors('captcha');
    expect(Tenant::query()->count())->toBe(1);
});

test('captcha returns a noncached image and stores an expiring challenge', function () {
    $this->get(route('signup.captcha'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertSessionHas('registration_captcha');
});

test('registration waits for email verification without dispatching provisioning', function () {
    Mail::fake();
    $this->withSession(['registration_captcha' => ['answer' => 'ABC234', 'expires' => time() + 600]])->post('/register', [
        'company' => 'Example', 'slug' => 'example', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-password-1', 'password_confirmation' => 'long-password-1', 'captcha' => 'abc234',
    ])->assertRedirect();
    expect(Tenant::query()->where('name', 'Example')->first()->provisioning_status)->toBe('awaiting_verification');
    Queue::assertNothingPushed();
});

test('email verification queues provisioning once and rejects replay', function () {
    $this->tenant->forceFill(['provisioning_status' => 'awaiting_verification', 'provisioning_owner' => ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-password-1']])->saveQuietly();
    $token = str_repeat('a', 64);
    Cache::put('registration.verify.'.hash('sha256', $token), $this->tenant->id, now()->addHour());
    $url = URL::temporarySignedRoute('signup.verify', now()->addHour(), ['token' => $token]);
    $this->get($url)->assertRedirect()->assertSessionHas('verified_registration', $this->tenant->id);
    expect($this->tenant->fresh()->provisioning_status)->toBe('pending')->and($this->tenant->fresh()->provisioning_owner['email_verified_at'])->not->toBeNull();
    Queue::assertPushed(ProvisionTenant::class, 1);
    $this->get($url)->assertGone();
});

test('a signed progress URL alone cannot automatically sign in', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $this->getJson($url)->assertOk()->assertJsonPath('redirect_url', null);
});

test('only a verified registration session receives a company handoff', function () {
    $this->tenant->forceFill(['provisioning_status' => 'ready'])->saveQuietly();
    $url = URL::temporarySignedRoute('signup.status', now()->addHour(), ['tenant' => $this->tenant]);
    $response = $this->withSession(['verified_registration' => $this->tenant->id, 'verified_registration_email' => 'owner@example.test'])->getJson($url)->assertOk();
    expect($response->json('redirect_url'))->toContain('/registration/complete/');
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
    Cache::put('registration.login.'.hash('sha256', $token), ['tenant_id' => $this->tenant->id, 'email' => 'owner@example.test'], now()->addMinutes(5));
    $url = 'http://northwind.test/registration/complete/'.$token;
    $this->get($url)->assertRedirect('/admin')->assertSessionHas(Auth::guard('web')->getName(), 1);
    $this->get($url)->assertForbidden();
});

test('registration form renders its required captcha and refresh button', function () {
    $this->get(route('signup.create'))->assertOk()->assertSee('name="captcha"', false)->assertSee('Refresh CAPTCHA');
});
