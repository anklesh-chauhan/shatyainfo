<?php

use App\Enums\FieldPlanningMode;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldVisitPlanningPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('persists a direct visit with no geography or plan and attaches its DCR', function () {
    $user = reportingUser('DirectPolicy');
    $this->actingAs($user);
    $visit = Visit::query()->create([
        'document_number' => 'DIRECT-1', 'employee_id' => $user->id,
        'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft',
    ]);
    app(FieldVisitPlanningPolicy::class)->validate($visit,
        app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr));
    expect($visit->fresh()->territory_id)->toBeNull()
        ->and($visit->fresh()->patch_id)->toBeNull()
        ->and($visit->salesDcr->user_id)->toBe($user->id)
        ->and($visit->salesDcr->visits()->count())->toBe(1);
});

it('enforces strict plan and geography requirements without mutating the visit', function () {
    $visit = new Visit(['employee_id' => 1, 'visit_date' => today(), 'visit_type' => 'unplanned']);
    try {
        app(FieldVisitPlanningPolicy::class)->validate($visit,
            app(FieldPlanningService::class)->preset(FieldPlanningMode::FormalMtp));
        $this->fail('Expected policy rejection');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain('territory_id', 'patch_id', 'sales_tour_plan_id');
    }
    expect($visit->exists)->toBeFalse();
});

it('requires a reason for flexible unplanned visits', function () {
    $visit = new Visit(['visit_date' => today(), 'visit_type' => 'unplanned']);
    $rules = app(FieldPlanningService::class)->preset(FieldPlanningMode::Flexible);
    expect(fn () => app(FieldVisitPlanningPolicy::class)->validate($visit, $rules, '  '))
        ->toThrow(ValidationException::class);
    app(FieldVisitPlanningPolicy::class)->validate($visit, $rules, 'Doctor requested an urgent call');
});

it('rejects invalid plan links even when plans are optional', function (array $attributes) {
    $visit = new Visit([...$attributes, 'employee_id' => 1, 'visit_date' => today(), 'visit_type' => 'planned']);
    expect(fn () => app(FieldVisitPlanningPolicy::class)->validate($visit,
        app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr)))
        ->toThrow(ValidationException::class);
})->with([
    [['sales_tour_plan_id' => 999]],
    [['sales_tour_plan_detail_id' => 999]],
    [['sales_tour_plan_id' => 999, 'sales_tour_plan_detail_id' => 999]],
]);

it('rejects a patch from another territory', function () {
    $one = reportingTerritory('One');
    $two = reportingTerritory('Two');
    $patch = reportingPatch($one, 'Patch');
    $visit = new Visit(['territory_id' => $two->id, 'patch_id' => $patch->id, 'visit_type' => 'unplanned']);
    expect(fn () => app(FieldVisitPlanningPolicy::class)->validate($visit,
        app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr)))
        ->toThrow(ValidationException::class);
});

it('validates plan owner date approval and patch for formal calls', function (string $case) {
    $rep = reportingUser('FormalPolicy');
    $other = reportingUser('OtherPolicy');
    $territory = reportingTerritory('Formal');
    $patch = reportingPatch($territory, 'Formal patch');
    $plan = SalesTourPlan::withoutEvents(fn () => SalesTourPlan::query()->create([
        'user_id' => $case === 'owner' ? $other->id : $rep->id,
        'month' => today()->format('Y-m'), 'approval_status' => $case === 'approval' ? 'draft' : 'approved',
    ]));
    $detail = SalesTourPlanDetail::withoutEvents(fn () => SalesTourPlanDetail::query()->create([
        'sales_tour_plan_id' => $plan->id, 'date' => $case === 'date' ? today()->addDay() : today(),
        'territory_id' => $territory->id,
    ]));
    if ($case !== 'patch') {
        $detail->patches()->attach($patch->id);
    }
    $visit = new Visit([
        'employee_id' => $rep->id, 'visit_date' => today(), 'visit_type' => 'planned',
        'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'sales_tour_plan_id' => $plan->id, 'sales_tour_plan_detail_id' => $detail->id,
    ]);
    $validate = fn () => app(FieldVisitPlanningPolicy::class)->validate($visit,
        app(FieldPlanningService::class)->preset(FieldPlanningMode::FormalMtp));
    if ($case === 'valid') {
        $validate();
        expect($visit->exists)->toBeFalse();
    } else {
        expect($validate)->toThrow(ValidationException::class);
    }
})->with(['owner', 'date', 'approval', 'patch', 'valid']);
