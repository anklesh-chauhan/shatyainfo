<?php

use App\Filament\Resources\TravelSegments\Pages\ListTravelSegments;
use App\Models\SalesDcr;
use App\Models\TravelSegment;
use App\Services\Travel\TravelSegmentValidation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('hides travel segments when their parent report is outside the users scope', function () {
    $owner = reportingUser('TravelSegmentOwner');
    $viewer = reportingUser('TravelSegmentViewer');
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:TravelSegment', 'web'));

    $dcr = SalesDcr::query()->create([
        'user_id' => $owner->id,
        'dcr_date' => today()->toDateString(),
        'approval_status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $segment = TravelSegment::query()->create([
        'sales_dcr_id' => $dcr->id,
        'distance_km' => 12,
        'distance_source' => 'manual',
        'is_auto_generated' => false,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    expect($viewer->can('AccessAllRecords'))->toBeFalse()
        ->and($viewer->hasAnyRole(['super_admin', 'administration_admin']))->toBeFalse()
        ->and($dcr->user_id)->toBe($owner->id)
        ->and($segment->sales_dcr_id)->toBe($dcr->id);

    $this->actingAs($viewer, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListTravelSegments::class)
        ->assertCanNotSeeTableRecords([$segment]);

    $viewer->givePermissionTo(Permission::findOrCreate('AccessAllRecords', 'web'));
    $this->actingAs($viewer->fresh(), 'web');
    Livewire::test(ListTravelSegments::class)->assertCanSeeTableRecords([$segment]);
});

it('rejects an unrelated customer in travel without a patch', function () {
    $owner = reportingUser('Travel Reporter');
    $other = reportingUser('Other Travel Customer Owner');
    $customer = reportingDoctor($other, 'Outside Travel Customer');
    $report = SalesDcr::forceCreate(['user_id' => $owner->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->actingAs($owner, 'web');

    expect(fn () => app(TravelSegmentValidation::class)->validate([
        'sales_dcr_id' => $report->id,
        'from_account_id' => $customer->id,
    ]))->toThrow(ValidationException::class);
});
