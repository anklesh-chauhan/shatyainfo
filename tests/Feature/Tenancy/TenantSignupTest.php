<?php

use App\Models\Tenant;
use App\Support\Tenancy\CompanyDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Mail::fake();
    $this->withSession(['registration_captcha' => ['answer' => 'ABC234', 'expires' => time() + 600]]);
});

it('shows the marketing page on the landlord host', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Shatya Info')
        ->assertSee('Create your company');
});

it('registers a company and keeps the owner off the public tenant columns', function () {
    $response = $this->post('/register', [
        'captcha' => 'ABC234',
        'company' => 'Northwind Pharma',
        'slug' => 'North-Wind',
        'name' => 'Asha Rao',
        'email' => 'asha@northwind.test',
        'password' => 'Long-password-1',
        'password_confirmation' => 'Long-password-1',
    ]);

    $tenant = Tenant::query()->where('domain', CompanyDomain::fromSlug('north-wind'))->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->provisioning_status)->toBe('awaiting_verification')
        ->and($tenant->name)->toBe('Northwind Pharma')
        ->and($tenant->provisioning_owner['email'])->toBe('asha@northwind.test')
        ->and($tenant->provisioning_owner['name'])->toBe('Asha Rao')
        ->and($tenant->database)->toStartWith('tenant_');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/register/'.$tenant->id.'/status');

    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertSee('Northwind Pharma')
        ->assertSee($tenant->domain);
});

it('rejects a reserved or taken company address', function () {
    $this->post('/register', tenantSignupPayload('admin'))
        ->assertSessionHasErrors('slug');

    Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Taken',
            'domain' => CompanyDomain::fromSlug('taken'),
            'database' => 'tenant_taken_signup',
            'provisioning_status' => 'ready',
        ])->save();
    });

    $this->post('/register', tenantSignupPayload('taken'))
        ->assertSessionHasErrors('slug');

    expect(Tenant::query()->count())->toBe(1);
});

it('requires a long mixed-case password', function () {
    $this->post('/register', tenantSignupPayload('short-pass', ['password' => 'short', 'password_confirmation' => 'short']))
        ->assertSessionHasErrors('password');
    $this->post('/register', tenantSignupPayload('lower-pass', ['password' => 'long-password-1', 'password_confirmation' => 'long-password-1']))
        ->assertSessionHasErrors('password');
});

it('checks company address availability while typing', function () {
    Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Taken',
            'domain' => CompanyDomain::fromSlug('taken'),
            'database' => 'tenant_taken_lookup',
            'provisioning_status' => 'ready',
        ])->save();
    });

    $this->getJson('/register/address-availability?slug=ab')
        ->assertOk()
        ->assertJson(['available' => false, 'status' => 'invalid']);
    $this->getJson('/register/address-availability?slug=admin')
        ->assertOk()
        ->assertJson(['available' => false, 'status' => 'reserved', 'message' => 'That address is reserved.']);
    $this->getJson('/register/address-availability?slug=taken')
        ->assertOk()
        ->assertJson(['available' => false, 'status' => 'taken']);
    $this->getJson('/register/address-availability?slug=Fresh-Co')
        ->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('domain', CompanyDomain::fromSlug('fresh-co'));
});

it('hides registration on a company host', function () {
    Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Acme',
            'domain' => 'acme.test',
            'database' => 'tenant_acme_signup',
            'provisioning_status' => 'ready',
        ])->save();
    });

    $this->get('http://acme.test/')
        ->assertRedirect('/admin/login');

    $this->get('http://acme.test/register')->assertNotFound();
    $this->post('http://acme.test/register', tenantSignupPayload('other'))->assertNotFound();
});

it('sends an existing company to its own sign-in page', function () {
    Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Acme',
            'domain' => CompanyDomain::fromSlug('acme'),
            'database' => 'tenant_acme_login',
            'provisioning_status' => 'ready',
        ])->save();
    });

    $this->post('/login', ['company' => 'acme'])
        ->assertRedirect(CompanyDomain::loginUrl(CompanyDomain::fromSlug('acme')));

    $this->post('/login', ['company' => 'missing'])
        ->assertSessionHasErrors('company');
});

it('refuses an unsigned setup page', function () {
    $tenant = Tenant::withoutEvents(function () {
        $tenant = new Tenant;
        $tenant->forceFill([
            'name' => 'Acme',
            'domain' => CompanyDomain::fromSlug('acme'),
            'database' => 'tenant_acme_status',
            'provisioning_status' => 'pending',
        ])->save();

        return $tenant;
    });

    $this->get('/register/'.$tenant->id.'/status')->assertForbidden();
});

function tenantSignupPayload(string $slug, array $overrides = []): array
{
    return array_merge([
        'captcha' => 'ABC234',
        'company' => 'Example Pharma',
        'slug' => $slug,
        'name' => 'Owner',
        'email' => $slug.'@example.test',
        'password' => 'Long-password-1',
        'password_confirmation' => 'Long-password-1',
    ], $overrides);
}
