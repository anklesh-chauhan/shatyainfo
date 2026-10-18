<?php

use App\Http\Requests\CompanyRegistrationRequest;
use App\Models\Tenant;
use App\Support\Tenancy\TenantBranding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'multitenancy.switch_tenant_tasks' => []]);
    DB::purge('mysql');
    Schema::connection('mysql')->create('tenants', function (Blueprint $table): void {
        $table->id();
        $table->string('registration_mobile')->nullable()->unique();
    });
});

afterEach(function () {
    Tenant::forgetCurrent();
    DB::purge('mysql');
});

function mobileRegistrationRequest(mixed $mobile): CompanyRegistrationRequest
{
    $request = new class extends CompanyRegistrationRequest
    {
        public function normalize(): void
        {
            $this->prepareForValidation();
        }
    };
    $request->merge(['mobile_number' => $mobile]);
    $request->normalize();

    return $request;
}

test('equivalent Indian mobile formats cannot register twice', function (string $mobile) {
    DB::connection('mysql')->table('tenants')->insert(['registration_mobile' => '+919876543210']);
    $request = mobileRegistrationRequest($mobile);
    $validator = Validator::make($request->all(), ['mobile_number' => $request->rules()['mobile_number']], $request->messages());
    expect($request->input('mobile_number'))->toBe('+919876543210');
    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('mobile_number'))->toBe('This mobile number is already registered.');
})->with(['9876543210', '+91 98765-43210', '00919876543210', '09876543210']);

test('registration requires a valid mobile number', function (mixed $mobile) {
    $request = mobileRegistrationRequest($mobile);
    expect(Validator::make($request->all(), ['mobile_number' => $request->rules()['mobile_number']])->fails())->toBeTrue();
})->with([null, '', '123', 'not-a-number']);

test('new mobile numbers pass validation', function () {
    $request = mobileRegistrationRequest('9876543210');
    expect(Validator::make($request->all(), ['mobile_number' => $request->rules()['mobile_number']])->passes())->toBeTrue();
});

test('panel branding follows the current tenant without leaking between tenants', function () {
    foreach (['Northwind', 'Contoso'] as $name) {
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 1, 'name' => $name, 'master_setup_status' => 'pending']);
        $tenant->makeCurrent();
        expect(TenantBranding::name())->toBe($name)->and(TenantBranding::logo())->toBeNull();
        expect(view('filament.components.tenant-brand')->render())->toContain($name)->not->toContain('Shatya Info');
        Tenant::forgetCurrent();
    }
});
