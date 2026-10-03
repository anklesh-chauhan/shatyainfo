<?php

use App\Filament\Resources\SalesTourPlans\Pages\CreateSalesTourPlan;
use App\Filament\Resources\SalesTourPlans\Pages\EditSalesTourPlan;
use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Models\JobRole;
use App\Models\Position;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('creates every day of the selected month and opens the plan', function () {
    $user = reportingUser('TourPlanner');

    foreach (['ViewAny:SalesTourPlan', 'View:SalesTourPlan', 'Create:SalesTourPlan', 'Update:SalesTourPlan', 'ViewOwn:SalesTourPlan'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $territory = reportingTerritory('Planner Territory');
    $role = JobRole::query()->create(['name' => 'Representative', 'code' => 'MTPPLN', 'level' => 1]);
    $position = Position::query()->create([
        'name' => 'Planner Position',
        'code' => 'MTPPLNPOS',
        'job_role_id' => $role->id,
    ]);
    $position->territories()->attach($territory->id);
    $user->employee->positions()->attach($position->id, ['is_primary' => true]);

    $this->actingAs($user);

    $month = '2026-10';

    livewire(CreateSalesTourPlan::class)
        ->set('data.month', $month)
        ->assertRedirect(SalesTourPlanResource::getUrl('edit', ['record' => SalesTourPlan::query()->first()]));

    $plan = SalesTourPlan::query()->where('user_id', $user->id)->first();

    expect($plan)->not->toBeNull()
        ->and($plan->approval_status)->toBe('draft')
        ->and($plan->created_by)->toBe($user->id)
        ->and($plan->details)->toHaveCount(Carbon::createFromFormat('Y-m', $month)->daysInMonth)
        ->and($plan->details->sortBy('date')->first()->date->toDateString())->toBe('2026-10-01')
        ->and($plan->details->every(fn (SalesTourPlanDetail $detail): bool => $detail->territory_id === $territory->id))->toBeTrue();

    livewire(EditSalesTourPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->assertFormSet(['month' => '2026-10'])
        ->call('save')
        ->assertHasNoFormErrors();

    livewire(CreateSalesTourPlan::class)
        ->set('data.month', $month);

    expect(SalesTourPlan::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SalesTourPlanDetail::query()->count())->toBe(31);
});

it('blocks tour plan months beyond the admin horizon', function () {
    $user = reportingUser('HorizonRep');

    foreach (['ViewAny:SalesTourPlan', 'Create:SalesTourPlan'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    VisitPreference::current()->update(['tour_plan_future_months' => 1]);

    $this->actingAs($user);

    livewire(CreateSalesTourPlan::class)
        ->set('data.month', now()->startOfMonth()->addMonth()->format('Y-m'))
        ->assertNoRedirect();

    expect(SalesTourPlan::query()->count())->toBe(0);

    livewire(CreateSalesTourPlan::class)
        ->set('data.month', now()->startOfMonth()->format('Y-m'))
        ->assertRedirect();

    expect(SalesTourPlan::query()->where('user_id', $user->id)->count())->toBe(1);
});
