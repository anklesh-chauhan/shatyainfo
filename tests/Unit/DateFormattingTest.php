<?php

use App\Http\Requests\RegistrationOnboardingRequest;
use App\Models\IntegrationSetting;
use App\Models\Tenant;
use App\Services\DateFormatService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('mysql');
    Schema::create('integration_settings', function (Blueprint $table): void {
        $table->id();
        $table->string('provider')->unique();
        $table->text('credentials')->nullable();
        $table->boolean('is_enabled')->default(false);
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->unsignedBigInteger('deleted_by')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
});

afterEach(function () {
    app()->forgetInstance(config('multitenancy.current_tenant_container_key'));
    DB::purge('mysql');
});

test('company settings persist and do not leak when switching tenants', function () {
    $service = app(DateFormatService::class);
    expect($service->dateFormat())->toBe('d/m/Y');
    $service->save('d M Y');
    expect((new DateFormatService)->dateFormat())->toBe('d M Y');

    $tenant = new Tenant;
    $tenant->id = 42;
    app()->instance(config('multitenancy.current_tenant_container_key'), $tenant);
    expect($service->dateFormat())->toBe('d/m/Y');
    $service->save('Y-m-d');
    expect($service->dateFormat())->toBe('Y-m-d');
    app()->forgetInstance(config('multitenancy.current_tenant_container_key'));
    expect($service->dateFormat())->toBe('d M Y');
});

test('global component and export formats follow the saved setting', function () {
    app(DateFormatService::class)->save('d-m-Y');
    expect(DatePicker::make('date')->getDisplayFormat())->toBe('d-m-Y')
        ->and(DatePicker::make('date')->isNative())->toBeFalse()
        ->and(DateTimePicker::make('date')->getDisplayFormat())->toBe('d-m-Y H:i:s')
        ->and(FilamentSchema::make()->getDefaultDateDisplayFormat())->toBe('d-m-Y')
        ->and(ExportColumn::make('visit_date')->formatState('2026-11-07'))->toBe('07-11-2026')
        ->and(ExportColumn::make('movement_at')->formatState('2026-11-07 13:04:05'))->toBe('07-11-2026 13:04:05');
});

test('invalid saved formats fall back and unsupported changes fail validation', function () {
    expect(fn () => app(DateFormatService::class)->save('unsupported'))
        ->toThrow(ValidationException::class);
    IntegrationSetting::query()->create([
        'provider' => app(DateFormatService::class)->storageKey(),
        'credentials' => ['date_format' => 'unsupported'],
        'is_enabled' => true,
    ]);
    expect(app(DateFormatService::class)->dateFormat())->toBe('d/m/Y');
});

test('registration onboarding asks for a supported format and preserves the selection', function () {
    $rules = (new RegistrationOnboardingRequest)->rules();
    $data = ['organization' => ['name' => 'Company', 'email' => 'owner@example.test', 'phone' => '9999999999', 'date_format' => 'm/d/Y']];
    expect(Validator::make($data, $rules)->passes())->toBeTrue();
    unset($data['organization']['date_format']);
    expect(Validator::make($data, $rules)->errors()->has('organization.date_format'))->toBeTrue();
    $data['organization']['date_format'] = 'unsupported';
    expect(Validator::make($data, $rules)->errors()->has('organization.date_format'))->toBeTrue();
    expect(Validator::make(['skip' => '1'], $rules)->passes())->toBeTrue();

    $tenant = new Tenant;
    $tenant->forceFill(['name' => 'Company', 'onboarding_data' => ['organization' => ['date_format' => 'm/d/Y']]]);
    $this->view('marketing.registration-onboarding', ['tenant' => $tenant, 'onboardingUrl' => '/setup', 'templateUrl' => '/template', 'errors' => new ViewErrorBag])
        ->assertSee('Date display format')->assertSee('value="m/d/Y" selected', false);
});
