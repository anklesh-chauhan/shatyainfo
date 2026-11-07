<?php

use App\Enums\FieldPlanningMode;
use App\Models\VisitPreference;
use App\Services\Field\FieldPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('publishes compatibility capabilities without changing tenant preferences', function () {
    $user = reportingUser('PlanningCompatibility');
    $preferences = VisitPreference::current();
    $preferences->update(['allow_backdated_dcr' => true, 'dcr_backdate_days' => 7]);
    $before = $preferences->fresh()->getAttributes();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')->assertSuccessful()
        ->assertJsonPath('data.field_planning.schema_version', 1)
        ->assertJsonPath('data.field_planning.mode', 'legacy')
        ->assertJsonPath('data.field_planning.supports_direct_calls', false)
        ->assertJsonPath('data.field_planning.supports_flexible_plans', false)
        ->assertJsonPath('data.field_planning.supports_mtp', true);

    expect($preferences->fresh()->getAttributes())->toBe($before);
});

it('returns the same capabilities at login and session refresh', function () {
    $user = reportingUser('PlanningLogin');
    $login = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'planning-test',
    ])->assertSuccessful();

    $this->withToken($login->json('token'))->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.field_planning', $login->json('user.field_planning'));
});

it('does not infer an active policy from a preset preview', function () {
    $service = app(FieldPlanningService::class);
    $before = $service->capabilities();
    foreach (FieldPlanningMode::cases() as $mode) {
        $service->preset($mode);
    }

    expect($service->preset(FieldPlanningMode::Legacy))->toBeNull()
        ->and($service->capabilities())->toBe($before)
        ->and(VisitPreference::query()->count())->toBe(0);
});

it('keeps plan requirements internally consistent across proposed presets', function (FieldPlanningMode $mode) {
    $rules = app(FieldPlanningService::class)->preset($mode);
    expect($rules)->not->toBeNull();
    if ($rules['require_approved_plan']) {
        expect($rules['require_plan'])->toBeTrue();
    }
    if ($mode !== FieldPlanningMode::FormalMtp) {
        expect($rules['require_plan'])->toBeFalse()
            ->and($rules['require_territory'])->toBeFalse()
            ->and($rules['require_patch'])->toBeFalse()
            ->and($rules['unplanned_calls'])->not->toBe('block');
    }
})->with([FieldPlanningMode::DirectDcr, FieldPlanningMode::Flexible, FieldPlanningMode::FormalMtp]);
