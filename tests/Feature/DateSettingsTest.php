<?php

use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\IntegrationSetting;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DateFormatService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('uses a consistent default and persists every supported date format', function () {
    $service = app(DateFormatService::class);
    expect($service->dateFormat())->toBe('d/m/Y');

    foreach (array_keys(DateFormatService::FORMATS) as $format) {
        $service->save($format);
        expect($service->dateFormat())->toBe($format)
            ->and((new DateFormatService)->dateFormat())->toBe($format)
            ->and($service->dateTimeFormat())->toBe($format.' H:i:s');
    }

    expect(IntegrationSetting::query()->where('provider', $service->storageKey())->count())->toBe(1);
});

it('rejects unsupported formats without changing the saved format', function () {
    $service = app(DateFormatService::class);
    $service->save('d-m-Y');
    expect(fn () => $service->save('invalid'))->toThrow(ValidationException::class);
    expect($service->dateFormat())->toBe('d-m-Y');
});

it('keeps date settings isolated when the current company changes', function () {
    $service = app(DateFormatService::class);
    $service->save('d-m-Y');
    $tenant = new Tenant;
    $tenant->id = 12345;
    app()->instance(config('multitenancy.current_tenant_container_key'), $tenant);

    try {
        expect($service->dateFormat())->toBe(DateFormatService::DEFAULT_FORMAT);
        $service->save('m/d/Y');
        expect($service->dateFormat())->toBe('m/d/Y');
    } finally {
        app()->forgetInstance(config('multitenancy.current_tenant_container_key'));
    }

    expect($service->dateFormat())->toBe('d-m-Y');
});

it('exposes the company date format in login and session responses without changing wire dates', function () {
    app(DateFormatService::class)->save('m/d/Y');
    $user = reportingUser('DateFormatApi');

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSuccessful()->assertJsonPath('user.date_format', 'm/d/Y');
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
        ->assertSuccessful()->assertJsonPath('data.date_format', 'm/d/Y');
    expect((new UserResource($user))->resolve()['date_format'])->toBe('m/d/Y');
});

it('applies saved formats to date pickers tables and infolists', function () {
    app(DateFormatService::class)->save('Y-m-d');
    expect(DatePicker::make('date')->getDisplayFormat())->toBe('Y-m-d')
        ->and(DatePicker::make('date')->isNative())->toBeFalse();
    $schema = Schema::make();
    expect($schema->getDefaultDateDisplayFormat())->toBe('Y-m-d')
        ->and($schema->getDefaultDateTimeDisplayFormat())->toBe('Y-m-d H:i:s');
});

it('edits the shared format from organization profile and protects it from non-administrators', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('administration_admin', 'web'));
    foreach (['ViewAny:Organization', 'View:Organization', 'Update:Organization', 'AccessAllRecords'] as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $organization = Organization::create(['name' => 'Date Company', 'status' => 'active']);

    livewire(EditOrganization::class, ['record' => $organization->id])->assertSuccessful()
        ->assertFormSet(['date_format' => 'd/m/Y'])
        ->fillForm(['date_format' => 'd M Y'])->call('save')
        ->assertHasNoFormErrors();
    expect(app(DateFormatService::class)->dateFormat())->toBe('d M Y');
    livewire(EditOrganization::class, ['record' => $organization->id])->assertFormSet(['date_format' => 'd M Y'])
        ->fillForm(['date_format' => 'unsupported'])->call('save')->assertHasFormErrors(['date_format']);

    $editor = User::factory()->create();
    foreach (['ViewAny:Organization', 'View:Organization', 'Update:Organization', 'AccessAllRecords'] as $permission) {
        $editor->givePermissionTo($permission);
    }
    $this->actingAs($editor);
    livewire(EditOrganization::class, ['record' => $organization->id])
        ->assertFormFieldIsHidden('date_format')->set('data.date_format', 'Y-m-d')->call('save')
        ->assertHasNoFormErrors();
    expect(app(DateFormatService::class)->dateFormat())->toBe('d M Y');
});

it('saves the company format when creating an organization profile', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['ViewAny:Organization', 'Create:Organization', 'AccessAllRecords'] as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    livewire(CreateOrganization::class)->assertSuccessful()->fillForm([
        'name' => 'New Date Company', 'status' => 'active', 'date_format' => 'd-m-Y',
    ])->call('create')->assertHasNoFormErrors();
    expect(app(DateFormatService::class)->dateFormat())->toBe('d-m-Y');
});
