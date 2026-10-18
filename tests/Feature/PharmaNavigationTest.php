<?php

use App\Filament\Pages\MyQuickLinks;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\User;
use App\Support\TopbarLinks;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ImageTagSeeder;
use Database\Seeders\IndustryTypeSeeder;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\PackagingTypeSeeder;
use Database\Seeders\PackingTypeSeeder;
use Database\Seeders\TypeMasterSeeder;
use Database\Seeders\UnitOfMeasurementSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config(['database.default' => 'mysql', 'database.connections.mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'cache.default' => 'array', 'session.driver' => 'array']);
    DB::purge('mysql');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });
    (require database_path('migrations/tenant/2026_10_18_210548_add_topbar_links_to_users_table.php'))->up();
    (require database_path('migrations/tenant/2025_03_10_160426_create_permission_tables.php'))->up();
    $this->user = User::withoutEvents(fn () => User::create(['name' => 'Rep', 'email' => 'rep@example.test', 'password' => 'password']));
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function (): void {
    DB::purge('mysql');
});

it('filters defaults and saved shortcuts by access while preserving order and empty choices', function (): void {
    $service = new TopbarLinks;
    $catalog = [VisitResource::class => ['label' => 'Visits', 'url' => '/visits'], 'allowed' => ['label' => 'Allowed', 'url' => '/allowed']];
    expect($service->selected($this->user, $catalog))->toBe([VisitResource::class]);
    $this->user->forceFill(['topbar_links' => ['allowed', 'forbidden', VisitResource::class, 'allowed']])->save();
    expect($service->selected($this->user->fresh(), $catalog))->toBe(['allowed', VisitResource::class]);
    $this->user->forceFill(['topbar_links' => []])->save();
    expect($service->selected($this->user->fresh(), $catalog))->toBe([]);
});

it('saves ordered shortcuts for only the signed in user and restores defaults', function (): void {
    $this->mock(TopbarLinks::class, function ($mock): void {
        $mock->shouldReceive('catalog')->andReturn(['visits' => ['label' => 'Visits', 'url' => '/visits'], 'dcr' => ['label' => 'DCR', 'url' => '/dcr']]);
        $mock->shouldReceive('selected')->andReturn([]);
    });
    $other = User::withoutEvents(fn () => User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password']));
    Livewire::test(MyQuickLinks::class)->fillForm(['links' => [['module' => 'dcr'], ['module' => 'visits']]])->call('save')->assertHasNoFormErrors()->assertRedirect();
    expect($this->user->fresh()->topbar_links)->toBe(['dcr', 'visits']);
    expect($other->fresh()->topbar_links)->toBeNull();
    Livewire::test(MyQuickLinks::class)->call('resetDefaults')->assertRedirect();
    expect($this->user->fresh()->topbar_links)->toBeNull();
});

it('rejects a forged shortcut instead of accepting an arbitrary destination', function (): void {
    $this->mock(TopbarLinks::class, function ($mock): void {
        $mock->shouldReceive('catalog')->andReturn(['visits' => ['label' => 'Visits', 'url' => '/visits']]);
        $mock->shouldReceive('selected')->andReturn([]);
    });
    Livewire::test(MyQuickLinks::class)->fillForm(['links' => [['module' => 'https://example.test']]])->call('save')->assertHasFormErrors();
    expect($this->user->fresh()->topbar_links)->toBeNull();
});

it('does not offer a protected resource to a user without permission', function (): void {
    expect((new TopbarLinks)->catalog())->not->toHaveKey(VisitResource::class);
});

it('seeds pharma master data idempotently and preserves existing custom records', function (): void {
    auth()->logout();
    foreach (['type_masters', 'categories', 'industry_types', 'lead_sources', 'packing_types', 'packaging_types', 'unit_of_measurements', 'image_tags'] as $name) {
        Schema::create($name, function (Blueprint $table) use ($name): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
            if (in_array($name, ['type_masters', 'categories', 'packing_types', 'packaging_types'])) {
                $table->text('description')->nullable();
            }
            if ($name === 'type_masters') {
                $table->string('typeable_type');
                $table->unsignedBigInteger('parent_id')->nullable();
            }
            if ($name === 'categories') {
                $table->string('modelable_type');
                $table->unsignedBigInteger('parent_id')->nullable();
            }
            if ($name === 'unit_of_measurements') {
                $table->string('abbreviation');
            }
            if ($name === 'image_tags') {
                $table->string('slug');
                $table->string('industry_type')->nullable();
                $table->boolean('is_active');
            }
        });
    }
    DB::table('industry_types')->insert(['name' => 'Existing custom industry']);
    $seeders = [TypeMasterSeeder::class, CategorySeeder::class, IndustryTypeSeeder::class, LeadSourceSeeder::class, PackingTypeSeeder::class, PackagingTypeSeeder::class, UnitOfMeasurementSeeder::class, ImageTagSeeder::class];
    $run = function () use ($seeders): void {
        Model::withoutEvents(fn () => $this->seed($seeders));
    };
    $run();
    $counts = collect(['type_masters', 'categories', 'industry_types', 'lead_sources', 'packing_types', 'packaging_types', 'unit_of_measurements', 'image_tags'])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
    $run();
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    expect(DB::table('type_masters')->where('name', 'Doctor')->exists())->toBeTrue();
    expect(DB::table('type_masters')->where('name', 'Retail Customer')->exists())->toBeFalse();
    expect(DB::table('industry_types')->where('name', 'Existing custom industry')->exists())->toBeTrue();
    expect(DB::table('industry_types')->where('name', 'Pharmaceuticals')->exists())->toBeTrue();
    expect(DB::table('image_tags')->whereIn('industry_type', ['fmcg', 'machinery'])->exists())->toBeFalse();
    expect(DB::table('categories')->where('name', 'Tablets')->exists())->toBeTrue();
    expect(DB::table('packing_types')->where('name', 'Blister Pack')->exists())->toBeTrue();
});

it('renders accessible shortcuts and their configuration link', function (): void {
    $permission = Permission::create(['name' => 'ViewAny:Visit', 'guard_name' => 'web']);
    $this->user->givePermissionTo($permission);
    expect((new TopbarLinks)->catalog())->toHaveKey(VisitResource::class);
    $html = view('filament.components.topbar-quick-links')->render();
    expect($html)->toContain('it-top-menu-link')->toContain('Configure quick links')->toContain(VisitResource::getUrl('index'))->toContain('Quick links and configuration');
    expect($html)->not->toContain('fi-btn');
});
