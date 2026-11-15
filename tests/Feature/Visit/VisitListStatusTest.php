<?php

use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Approval;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('writes visit list completion and approval status without checkout or an approval', function () {
    $user = reportingUser('VisitListStatus');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Visit', 'web'));
    $territory = reportingTerritory('Visit List Territory');
    $patch = reportingPatch($territory, 'Visit List Patch');
    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->forceCreate([
        'document_number' => 'VIS-LIST-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'started',
        'start_time' => now(),
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    $this->actingAs($user, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListVisits::class)
        ->assertActionHidden(TestAction::make('send_for_approval')->table($visit))
        ->callAction(TestAction::make('complete')->table($visit));

    $visit->refresh();
    expect($visit->visit_status)->toBe('completed')
        ->and($visit->approval_status)->toBe('draft')
        ->and($visit->end_time)->toBeNull()
        ->and($visit->checkout_latitude)->toBeNull()
        ->and(Approval::query()->count())->toBe(0);

    $page = Livewire::test(ListVisits::class);
    $loaded = $page->instance()->getTableRecord((string) $visit->getKey());
    $action = $page->instance()->getTable()->getAction('send_for_approval');

    expect($loaded)->not->toBeNull()
        ->and($loaded->visit_status)->toBe('completed')
        ->and($action)->not->toBeNull();

    $action->record($loaded);
    $action->call(['record' => $loaded]);

    $visit->refresh();
    expect($visit->visit_status)->toBe('completed')
        ->and($visit->approval_status)->toBe('pending')
        ->and($visit->end_time)->toBeNull()
        ->and($visit->checkout_latitude)->toBeNull()
        ->and(Approval::query()->count())->toBe(0);
});
