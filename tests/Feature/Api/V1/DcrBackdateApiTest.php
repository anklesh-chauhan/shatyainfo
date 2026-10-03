<?php

use App\Models\ExpenseType;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('opens today and the configured number of earlier days', function () {
    $user = reportingUser('BackdateWindow');
    Sanctum::actingAs($user);

    VisitPreference::current()->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 2,
    ]);

    $earliest = today()->subDays(2)->toDateString();

    $this->getJson('/api/v1/dcrs/today?date='.$earliest)
        ->assertSuccessful()
        ->assertJsonPath('data.dcr_date', $earliest)
        ->assertJsonPath('data.reportable', true)
        ->assertJsonPath('reporting.backdate_days', 2)
        ->assertJsonPath('reporting.earliest_date', $earliest)
        ->assertJsonPath('reporting.latest_date', today()->toDateString());

    $this->getJson('/api/v1/dcrs/today?date='.today()->subDays(3)->toDateString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    $this->getJson('/api/v1/dcrs/today?date='.today()->addDay()->toDateString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps reporting on today when backdated DCR is turned off', function () {
    $user = reportingUser('TodayOnlyDcr');
    Sanctum::actingAs($user);

    VisitPreference::current()->update([
        'allow_backdated_dcr' => false,
        'dcr_backdate_days' => 10,
    ]);

    expect(VisitPreference::current()->dcrBackdateDays())->toBe(0);

    $this->getJson('/api/v1/dcrs/today')
        ->assertSuccessful()
        ->assertJsonPath('data.reportable', true)
        ->assertJsonPath('reporting.allow_backdated', false);

    $this->getJson('/api/v1/dcrs/today?date='.today()->subDay()->toDateString())
        ->assertUnprocessable()
        ->assertJsonPath('errors.date.0', 'DCR reporting is open for today only.');
});

it('returns an existing DCR outside the window without letting it be filed', function () {
    $user = reportingUser('LockedDcr');
    $date = today()->subDays(12)->toDateString();
    $dcr = SalesDcr::query()->create([
        'user_id' => $user->id,
        'dcr_date' => $date,
        'approval_status' => 'draft',
    ]);
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL-BD', 'is_active' => true]);

    VisitPreference::current()->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 3,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/dcrs/today?date='.$date)
        ->assertSuccessful()
        ->assertJsonPath('data.id', $dcr->id)
        ->assertJsonPath('data.reportable', false);

    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dcr');

    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/expenses', [
        'expense_type_id' => $type->id,
        'amount' => 80,
    ])->assertUnprocessable()->assertJsonValidationErrors('dcr');

    expect(SalesDcr::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($dcr->fresh()->approval_status)->toBe('draft');
});

it('submits a visit DCR from an open back date', function () {
    VisitPreference::current()->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 2,
    ]);

    $territory = reportingTerritory('Backdate Territory');
    $patch = reportingPatch($territory, 'Backdate Patch');
    $user = reportingUser('BackdateSubmit');
    $this->actingAs($user);

    $visit = Visit::query()->create([
        'document_number' => 'VIS-BD-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today()->subDay()->toDateString(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/dcrs/'.$visit->sales_dcr_id.'/submit')
        ->assertSuccessful()
        ->assertJsonPath('data.approval_status', 'submitted')
        ->assertJsonPath('data.dcr_date', today()->subDay()->toDateString());
});

it('rejects a DCR date that is not a calendar day', function () {
    Sanctum::actingAs(reportingUser('BadDcrDate'));

    $this->getJson('/api/v1/dcrs/today?date=01-10-2026')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('stores a backdate window between one and thirty one days', function () {
    $preference = VisitPreference::current();

    $preference->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 100,
    ]);

    expect($preference->fresh()->dcr_backdate_days)->toBe(31);

    $preference->update([
        'allow_backdated_dcr' => true,
        'dcr_backdate_days' => 0,
    ]);

    expect($preference->fresh()->dcrBackdateDays())->toBe(1);
});
