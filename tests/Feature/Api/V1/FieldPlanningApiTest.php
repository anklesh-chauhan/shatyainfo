<?php

use App\Enums\FieldPlanningMode;
use App\Filament\Pages\SalesAssistant;
use App\Models\VisitPreference;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

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

it('aligns monthly plan API and assistant capabilities with the active method', function (FieldPlanningMode $mode) {
    $user = reportingUser('MethodCapabilities');
    foreach (['Manage:FieldPlanningPreference', 'Create:SalesTourPlan'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Sanctum::actingAs($user);
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($user, [
        'mode' => $mode->value,
        'rules' => app(FieldPlanningService::class)->preset($mode),
        'reason' => 'Check selected reporting method',
        'proposed_effective_on' => null,
    ], 0);
    $settings->activate($user, $revision->id, 1);
    $supportsMtp = $mode === FieldPlanningMode::FormalMtp;
    $this->getJson('/api/v1/me')->assertSuccessful()->assertJsonPath('data.field_planning.supports_mtp', $supportsMtp);
    $features = $this->getJson('/api/v1/salesperson-ai/roadmap')->assertSuccessful()->json('data');
    expect(in_array('tour_plan_draft', array_column($features, 'key'), true))->toBe($supportsMtp);
    if ($supportsMtp) {
        $this->getJson('/api/v1/monthly-tour-plans/options')->assertSuccessful()->assertJsonPath('data.can_create', true);
        $this->getJson('/api/v1/tour-plan-draft?month='.today()->format('Y-m'))->assertSuccessful();
    } else {
        foreach (['monthly-tour-plans/options', 'monthly-tour-plans?month='.today()->format('Y-m'), 'monthly-tour-plans/inbox?month='.today()->format('Y-m'), 'monthly-tour-plans/1', 'tour-plan-draft?month='.today()->format('Y-m')] as $endpoint) {
            $this->getJson('/api/v1/'.$endpoint)->assertForbidden();
        }
        $this->getJson('/api/v1/todays-priorities')->assertSuccessful();
        $this->getJson('/api/v1/day-route')->assertSuccessful()->assertJsonPath('data.stops', []);
    }
    $this->actingAs($user);
    $page = app(SalesAssistant::class);
    $page->mount();
    expect($page->tourDraft !== [])->toBe($supportsMtp);
})->with([FieldPlanningMode::DirectDcr, FieldPlanningMode::Flexible, FieldPlanningMode::FormalMtp]);
