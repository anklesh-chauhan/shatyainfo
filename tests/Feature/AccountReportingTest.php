<?php

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\RatingType;
use App\Models\TypeMaster;
use App\Models\User;
use App\Models\Visit;
use App\Services\AccountReportingService;
use Database\Seeders\AccountReportingMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function reportingFoundationAccount(): AccountMaster
{
    $user = User::factory()->create();
    $type = TypeMaster::create(['name' => 'Doctor', 'typeable_type' => AccountMaster::class]);

    return AccountMaster::create(['name' => 'Reporting Account', 'owner_id' => $user->id, 'type_master_id' => $type->id]);
}

it('seeds configurable reporting masters without overwriting tenant customization', function () {
    $this->seed(AccountReportingMasterSeeder::class);
    $master = AccountReportingMaster::firstOrFail();
    $master->update(['name' => 'Local label', 'is_active' => false]);
    $count = AccountReportingMaster::count();
    $this->seed(AccountReportingMasterSeeder::class);
    expect(AccountReportingMaster::count())->toBe($count)->and($master->fresh()->name)->toBe('Local label')->and($master->fresh()->is_active)->toBeFalse();
});

it('allows independent dated division segments but rejects overlap within a division', function () {
    $account = reportingFoundationAccount();
    $rating = RatingType::create(['name' => 'A']);
    $type = TypeMaster::create(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $division = OrganizationalUnit::create(['name' => 'Division One', 'code' => 'ONE', 'type_master_id' => $type->id, 'is_active' => true]);
    $account->divisionProfiles()->create(['rating_type_id' => $rating->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $account->divisionProfiles()->create(['division_ou_id' => $division->id, 'rating_type_id' => $rating->id, 'effective_from' => '2026-01-01']);
    $account->divisionProfiles()->create(['rating_type_id' => $rating->id, 'effective_from' => '2026-07-01']);
    expect($account->divisionProfiles()->count())->toBe(3);
    expect(fn () => $account->divisionProfiles()->create(['rating_type_id' => $rating->id, 'effective_from' => '2026-06-01']))->toThrow(ValidationException::class);
});

it('requires complete monetary potential measurements', function () {
    $account = reportingFoundationAccount();
    $rating = RatingType::create(['name' => 'A']);
    $unit = AccountReportingMaster::create(['kind' => 'potential_unit', 'code' => 'revenue', 'name' => 'Revenue', 'is_monetary' => true]);
    $data = ['rating_type_id' => $rating->id, 'effective_from' => '2026-01-01', 'potential_value' => 12000, 'potential_unit_id' => $unit->id, 'potential_period' => 'monthly', 'assessed_on' => '2026-01-01'];
    expect(fn () => $account->divisionProfiles()->create($data))->toThrow(ValidationException::class);
    $record = $account->divisionProfiles()->create($data + ['currency' => 'INR']);
    expect($record->potential_value)->toBe('12000.00')->and($record->currency)->toBe('INR');
});

it('validates monthly call target boundaries and overlapping windows', function () {
    $account = reportingFoundationAccount();
    expect(fn () => $account->callTargets()->create(['calls_per_month' => 2, 'effective_from' => '2026-01-02']))->toThrow(ValidationException::class);
    $account->callTargets()->create(['calls_per_month' => 2, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31']);
    $account->callTargets()->create(['calls_per_month' => 4, 'effective_from' => '2026-02-01']);
    expect(fn () => $account->callTargets()->create(['calls_per_month' => 3, 'effective_from' => '2026-01-01']))->toThrow(ValidationException::class);
});

it('records owner territory and patch changes without modifying older snapshots', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
    $account = reportingFoundationAccount();
    $initial = $account->assignmentHistory()->firstOrFail();
    $territory = reportingTerritory('History Territory');
    $patch = reportingPatch($territory, 'History Patch');
    $this->travel(1)->days();
    $account->update(['territory_id' => $territory->id, 'owner_id' => User::factory()->create()->id]);
    $this->travel(1)->days();
    $account->patches()->attach($patch);
    expect($initial->fresh()->owner_id)->toBe($initial->owner_id)->and($initial->fresh()->effective_to)->not->toBeNull();
    $current = $account->assignmentHistory()->whereNull('effective_to')->firstOrFail();
    expect($current->patch_ids)->toBe([$patch->id])->and($current->territory_id)->toBe($territory->id);
    $this->travel(1)->days();
    $account->patches()->detach($patch);
    expect($current->fresh()->patch_ids)->toBe([$patch->id])->and($account->assignmentHistory()->whereNull('effective_to')->firstOrFail()->patch_ids)->toBe([]);
    $this->travelBack();
});

it('enforces type-specific activation rules and rejects self affiliations', function () {
    $account = reportingFoundationAccount();
    $account->typeMaster->update(['activation_requirements' => ['territory', 'contact', 'specialty']]);
    $active = AccountStatus::where('code', 'active')->firstOrFail();
    expect(fn () => $account->update(['account_status_id' => $active->id]))->toThrow(ValidationException::class);
    $specialty = AccountReportingMaster::create(['code' => 'general', 'kind' => 'specialty', 'name' => 'General']);
    $account->update(['territory_id' => reportingTerritory('Activation')->id, 'phone_number' => '9000000000', 'specialty_id' => $specialty->id, 'account_status_id' => $active->id]);
    expect($account->fresh()->account_status_id)->toBe($active->id);
    expect(fn () => $account->update(['institution_account_id' => $account->id]))->toThrow(ValidationException::class);
});

it('counts distinct completed calls with historical targets and preserves missing targets', function () {
    $user = fieldSalesUser('Reach Reporting');
    $this->actingAs($user);
    $account = reportingDoctor($user, 'Reach Account');
    $missing = reportingDoctor($user, 'No Target');
    $account->callTargets()->create(['calls_per_month' => 4, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31']);
    $account->callTargets()->create(['calls_per_month' => 1, 'effective_from' => '2026-02-01']);
    $territory = reportingTerritory('Reach Visits');
    $patch = Patch::withoutEvents(fn () => reportingPatch($territory, 'Reach Visits'));
    foreach (['completed', 'draft'] as $status) {
        $visit = Visit::withoutEvents(fn () => Visit::create(['document_number' => 'REACH-'.$status, 'territory_id' => $territory->id, 'patch_id' => $patch->id, 'employee_id' => $user->id, 'visit_date' => '2026-01-10', 'visit_status' => $status]));
        $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $account->id]);
    }
    $service = app(AccountReportingService::class);
    $row = $service->monthlyQuery('2026-01')->findOrFail($account->id);
    expect($service->metrics($row))->toBe(['target' => 4, 'completed' => 1, 'gap' => 3, 'achievement' => 25.0, 'reached' => true]);
    expect($service->metrics($service->monthlyQuery('2026-01')->findOrFail($missing->id))['target'])->toBeNull();
});

it('saves reporting details and activation atomically through the account api', function () {
    $user = fieldSalesUser('Reporting API');
    Sanctum::actingAs($user);
    $type = TypeMaster::create(['name' => 'Doctor API', 'typeable_type' => AccountMaster::class, 'activation_requirements' => ['patch']]);
    $patch = Patch::withoutEvents(fn () => reportingPatch(reportingTerritory('Reporting API'), 'Reporting API'));
    $rating = RatingType::create(['name' => 'A API']);
    $response = $this->postJson('/api/v1/accounts', ['name' => 'API Report Account', 'type_master_id' => $type->id, 'patch_ids' => [$patch->id], 'account_status_id' => AccountStatus::where('code', 'active')->value('id'),
        'division_profiles' => [['rating_type_id' => $rating->id, 'effective_from' => '2026-01-01']],
        'call_targets' => [['calls_per_month' => 2, 'effective_from' => '2026-01-01']],
    ])->assertSuccessful()->assertJsonPath('data.call_targets.0.calls_per_month', 2);
    $this->postJson('/api/v1/accounts', ['name' => 'Must Roll Back', 'type_master_id' => $type->id, 'call_targets' => [['calls_per_month' => 2, 'effective_from' => '2026-01-02']]])->assertUnprocessable();
    expect(AccountMaster::where('name', 'Must Roll Back')->exists())->toBeFalse();
    $this->patchJson('/api/v1/accounts/'.$response->json('data.id'), ['call_targets' => [['id' => 999999, 'calls_per_month' => 2, 'effective_from' => '2026-03-01']]])->assertUnprocessable();
});
