<?php

use App\Filament\Resources\SalesDcrs\Pages\CreateSalesDcr;
use App\Filament\Resources\SalesDcrs\Pages\EditSalesDcr;
use App\Models\SalesDcr;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('creates a DCR only inside the reporting window', function () {
    $user = reportingUser('DcrCreateWindow');

    foreach (['ViewAny:SalesDcr', 'Create:SalesDcr'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $this->actingAs($user);

    VisitPreference::current()->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 2,
    ]);

    livewire(CreateSalesDcr::class)
        ->set('data.dcr_date', today()->subDays(10)->toDateString())
        ->set('data.user_id', $user->id)
        ->set('data.approval_status', 'draft')
        ->call('create')
        ->assertHasFormErrors(['dcr_date']);

    expect(SalesDcr::query()->count())->toBe(0);

    livewire(CreateSalesDcr::class)
        ->set('data.dcr_date', today()->subDay()->toDateString())
        ->set('data.user_id', $user->id)
        ->set('data.approval_status', 'draft')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SalesDcr::query()->where('user_id', $user->id)->whereDate('dcr_date', today()->subDay())->count())->toBe(1);
});

it('keeps a DCR that is already outside the reporting window', function () {
    $user = reportingUser('DcrEditLocked');

    foreach (['ViewAny:SalesDcr', 'Update:SalesDcr'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $this->actingAs($user);

    VisitPreference::current()->update([
        'allow_backdated_dcr' => false,
    ]);

    $dcr = SalesDcr::query()->create([
        'user_id' => $user->id,
        'dcr_date' => today()->subDays(20)->toDateString(),
        'approval_status' => 'draft',
    ]);

    livewire(EditSalesDcr::class, ['record' => $dcr->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($dcr->fresh()->dcr_date->toDateString())->toBe(today()->subDays(20)->toDateString())
        ->and($dcr->fresh()->approval_status)->toBe('draft');
});
