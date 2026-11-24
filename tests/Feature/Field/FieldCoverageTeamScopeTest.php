<?php

use App\Filament\Pages\FieldCoverage;
use App\Models\FieldUserHq;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('blocks Manage:FieldTeam alone and lets managers with ViewAny:AccountMaster review another user', function () {
    $manager = reportingUser('CoverageManager');
    $subject = reportingUser('CoverageSubject');
    $manager->givePermissionTo(Permission::findOrCreate('Manage:FieldTeam', 'web'));
    $hq = reportingLocation('Coverage Subject HQ');
    $doctor = reportingDoctor($subject, 'Coverage Subject Doctor');
    FieldUserHq::query()->create([
        'user_id' => $subject->id,
        'location_master_id' => $hq->id,
        'updated_by' => $subject->id,
    ]);

    expect($manager->can('Manage:FieldTeam'))->toBeTrue()
        ->and($manager->can('ViewAny:AccountMaster'))->toBeFalse()
        ->and($manager->can('AccessAllRecords'))->toBeFalse()
        ->and($manager->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse();

    $this->actingAs($manager, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(FieldCoverage::canAccess())->toBeFalse();
    Livewire::test(FieldCoverage::class)->assertForbidden();

    Sanctum::actingAs($manager);
    $this->getJson('/api/v1/field-coverage?month='.today()->format('Y-m'))
        ->assertForbidden();
    $teamQuery = '/api/v1/field-coverage?include_team=1&user_id='.$subject->id;
    $this->getJson($teamQuery)->assertForbidden();

    $manager->givePermissionTo(Permission::findOrCreate('ViewAny:AccountMaster', 'web'));
    $manager = $manager->fresh();
    expect(FieldCoverage::canAccess())->toBeTrue();

    $this->actingAs($manager, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = Livewire::test(FieldCoverage::class)
        ->fillForm(['user_id' => $subject->id])
        ->assertCanSeeTableRecords([(string) $doctor->id])
        ->assertSee('Coverage Subject HQ');

    expect($page->instance()->coverageReport()['user_id'])->toBe($subject->id);

    Sanctum::actingAs($manager);
    $this->getJson('/api/v1/field-coverage?month='.today()->format('Y-m'))
        ->assertSuccessful()
        ->assertJsonPath('data.user_id', $manager->id)
        ->assertJsonPath('data.customers', [])
        ->assertJsonMissingPath('team_review');
    $this->getJson($teamQuery.'&hq_id='.$hq->id)->assertOk()
        ->assertJsonPath('data.user_id', $subject->id)
        ->assertJsonPath('data.customers.0.account_id', $doctor->id)
        ->assertJsonPath('data.coverage_percent', $page->instance()->coverageReport()['coverage_percent'])
        ->assertJsonPath('team_review.hqs.0.id', $hq->id)
        ->assertJsonFragment(['id' => $subject->id, 'name' => $subject->name, 'hq_id' => $hq->id]);
    $otherHq = reportingLocation('Different HQ');
    $this->getJson($teamQuery.'&hq_id='.$otherHq->id)->assertUnprocessable()->assertJsonValidationErrors('hq_id');
    $this->getJson('/api/v1/field-coverage?user_id=999999')->assertNotFound();

    $manager->revokePermissionTo('Manage:FieldTeam');
    Sanctum::actingAs($manager->fresh());
    $this->getJson($teamQuery)->assertForbidden();
    $this->getJson('/api/v1/field-coverage?include_team=1')->assertOk()
        ->assertJsonPath('data.user_id', $manager->id)->assertJsonMissingPath('team_review');
});
