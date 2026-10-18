<?php

use App\Filament\Resources\EDetailingMaterials\Pages\CreateEDetailingMaterial;
use App\Http\Middleware\IdentifyTenant;
use App\Models\EDetailingMaterial;
use App\Models\EDetailingPresentation;
use App\Models\User;
use App\Models\Visit;
use App\Services\Visit\VisitEnforcementService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['database.default' => 'mysql', 'database.connections.mysql' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ], 'cache.default' => 'array', 'session.driver' => 'array']);
    DB::purge('mysql');
    $this->withoutMiddleware(IdentifyTenant::class);
    $this->instance(VisitEnforcementService::class, Mockery::mock(VisitEnforcementService::class));
    Storage::fake('local');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });
    Schema::create('item_masters', function (Blueprint $table): void {
        $table->id();
        $table->string('item_name');
        $table->softDeletes();
    });
    Schema::create('visits', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('employee_id');
        $table->timestamp('start_time')->nullable();
        $table->timestamp('end_time')->nullable();
        $table->string('visit_status');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('media', function (Blueprint $table): void {
        $table->id();
        $table->morphs('model');
        $table->softDeletes();
    });
    Schema::create('visitable_visits', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('visit_id');
        $table->morphs('visitable');
    });
    Schema::create('sgip_distributions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('visit_id');
        $table->softDeletes();
    });
    (require database_path('migrations/tenant/2026_10_18_183759_create_e_detailing_tables.php'))->up();
    (require database_path('migrations/tenant/2025_03_10_160426_create_permission_tables.php'))->up();
    $this->rep = User::withoutEvents(fn () => User::create(['name' => 'Rep', 'email' => 'rep@example.test', 'password' => 'password']));
    $this->visit = Visit::withoutEvents(fn () => Visit::create(['employee_id' => $this->rep->id, 'visit_status' => 'started', 'start_time' => now()]));
    Sanctum::actingAs($this->rep);
    $this->material = EDetailingMaterial::factory()->create(['title' => 'Product presentation']);
    $this->payload = ['material_id' => $this->material->id, 'content_version' => $this->material->content_version, 'notes' => 'Discussed product information.'];
    $this->endpoint = '/api/v1/visits/'.$this->visit->id.'/e-detailing';
});

afterEach(function (): void {
    DB::purge('mysql');
});

it('lists published material without leaking private paths and streams the authenticated file', function (): void {
    EDetailingMaterial::factory()->create(['title' => 'Draft', 'is_active' => false]);
    $this->getJson($this->endpoint)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can_record', true)->assertJsonMissingPath('data.0.file_path');
    $this->get($this->endpoint.'/'.$this->material->id.'/file?version='.$this->material->content_version)
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('rejects another representatives visit for reads downloads and recording', function (): void {
    $other = User::withoutEvents(fn () => User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']));
    Sanctum::actingAs($other);
    $this->getJson($this->endpoint)->assertNotFound();
    $this->getJson($this->endpoint.'/'.$this->material->id.'/file?version='.$this->material->content_version)->assertNotFound();
    $this->postJson($this->endpoint, $this->payload)->assertNotFound();
});

it('records a version once and preserves its history after editing the title', function (): void {
    $this->postJson($this->endpoint, $this->payload)->assertCreated()->assertJsonPath('data.material_title', 'Product presentation');
    $this->postJson($this->endpoint, $this->payload)->assertOk();
    $this->material->update(['title' => 'New title', 'is_active' => false]);
    expect(EDetailingPresentation::count())->toBe(1);
    $this->getJson($this->endpoint)->assertJsonCount(0, 'data')->assertJsonPath('presentations.0.material_title', 'Product presentation');
});

it('only records during an active checked in visit', function (): void {
    foreach (['draft', 'completed', 'cancelled'] as $status) {
        $this->visit->forceFill(['visit_status' => $status])->saveQuietly();
        $this->postJson($this->endpoint, $this->payload)->assertUnprocessable();
    }
    $this->visit->forceFill(['visit_status' => 'started', 'end_time' => now()])->saveQuietly();
    $this->postJson($this->endpoint, $this->payload)->assertUnprocessable();
    expect(EDetailingPresentation::count())->toBe(0);
});

it('rejects stale versions unpublished materials and invalid notes', function (): void {
    $this->postJson($this->endpoint, array_replace($this->payload, ['content_version' => str_repeat('a', 64)]))->assertConflict();
    $this->getJson($this->endpoint.'/'.$this->material->id.'/file?version='.str_repeat('a', 64))->assertConflict();
    $this->postJson($this->endpoint, array_replace($this->payload, ['notes' => str_repeat('x', 2001)]))->assertUnprocessable();
    $this->material->update(['is_active' => false]);
    $this->postJson($this->endpoint, $this->payload)->assertNotFound();
    $this->getJson($this->endpoint.'/'.$this->material->id.'/file?version='.$this->material->content_version)->assertNotFound();
});

it('rejects foreign company paths and unsupported file contents', function (): void {
    Storage::disk('local')->put('e-detailing/tenant-other/stolen.pdf', '%PDF-1.4');
    expect(fn () => EDetailingMaterial::create(['title' => 'Foreign', 'file_path' => 'e-detailing/tenant-other/stolen.pdf']))->toThrow(ValidationException::class);
    $path = EDetailingMaterial::uploadDirectory().'/invalid.pdf';
    Storage::disk('local')->put($path, '<script>not a PDF</script>');
    expect(fn () => EDetailingMaterial::create(['title' => 'Invalid', 'file_path' => $path]))->toThrow(ValidationException::class);
});

it('allows only administrators to manage and preview the library', function (): void {
    expect(Gate::forUser($this->rep)->allows('create', EDetailingMaterial::class))->toBeFalse();
    $this->getJson(route('e-detailing.preview', $this->material))->assertForbidden();
    Role::create(['name' => 'administration_admin', 'guard_name' => 'web']);
    $this->rep->assignRole('administration_admin');
    expect(Gate::forUser($this->rep->fresh())->allows('create', EDetailingMaterial::class))->toBeTrue();
});

it('requires authentication', function (): void {
    auth()->forgetGuards();
    $this->app['auth']->forgetGuards();
    $this->getJson($this->endpoint)->assertUnauthorized();
});

it('uploads a private image through the admin form as a draft', function (): void {
    Role::create(['name' => 'administration_admin', 'guard_name' => 'web']);
    $this->rep->assignRole('administration_admin');
    $this->actingAs($this->rep, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateEDetailingMaterial::class)
        ->fillForm([
            'title' => 'New product image',
            'file_path' => UploadedFile::fake()->image('product.png'),
            'sort_order' => 1,
            'is_active' => false,
        ])->call('create')->assertHasNoFormErrors();
    $material = EDetailingMaterial::where('title', 'New product image')->firstOrFail();
    expect($material->mime_type)->toBe('image/png')->and($material->is_active)->toBeFalse();
    Storage::disk('local')->assertExists($material->file_path);
});
