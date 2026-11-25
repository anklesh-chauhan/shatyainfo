<?php

use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\Quote;
use App\Models\Visit;
use Database\Seeders\ConfigDrivenShieldPermissionSeeder;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('maps visit create and update access onto sales roles', function () {
    expect(config('permission-map.roles.sales_user.VisitResource'))->toBe('C')
        ->and(config('permission-map.roles.sales_admin.VisitResource'))->toBe('F');
});

it('lets a sales user open and authorize visit edit after permissions are seeded', function () {
    $this->seed(ConfigDrivenShieldPermissionSeeder::class);

    $role = Role::findByName('sales_user', 'web');

    expect($role->hasPermissionTo('ViewAny:Visit'))->toBeTrue()
        ->and($role->hasPermissionTo('View:Visit'))->toBeTrue()
        ->and($role->hasPermissionTo('Create:Visit'))->toBeTrue()
        ->and($role->hasPermissionTo('Update:Visit'))->toBeTrue()
        ->and($role->hasPermissionTo('ViewOwnOU:Visit'))->toBeTrue();

    $user = reportingUser('SalesVisitEditor');
    $user->assignRole('sales_user');

    $territory = reportingTerritory('Sales Visit Terr');
    $patch = reportingPatch($territory, 'Sales Visit Patch');

    $this->actingAs($user);

    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->forceCreate([
        'document_number' => 'VIS-PERM-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    expect($user->can('Update:Visit'))->toBeTrue()
        ->and($user->can('update', $visit))->toBeTrue()
        ->and($visit->created_by)->toBe($user->id);

    livewire(EditVisit::class, ['record' => $visit->getKey()])
        ->assertSuccessful();
});

it('loads the current sales document totals together when requested from a visit', function () {
    $user = fieldReportingUser('Visit sales totals');
    $this->actingAs($user);

    $territory = reportingTerritory('Visit totals');
    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->forceCreate([
        'document_number' => 'VIS-TOTALS',
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => reportingPatch($territory, 'Totals patch')->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    $page = livewire(EditVisit::class, ['record' => $visit->getKey()])
        ->assertSee('visit-sales-document-totals');

    $quote = Quote::withoutEvents(fn (): Quote => Quote::forceCreate([
        'document_number' => 'QUOTE-TOTALS',
        'account_master_id' => reportingDoctor($user, 'Visit totals account')->id,
        'contact_detail_id' => reportingContact('Visit', 'Totals')->id,
        'date' => today(),
        'status' => 'draft',
        'total' => 1234.50,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));
    $visit->visitDocumentLinks()->create(['documentable_type' => Quote::class, 'documentable_id' => $quote->id]);

    $targets = [];
    foreach ($page->instance()->getSchema('form')->getFlatComponents() as $entry) {
        if ($entry instanceof TextEntry && $entry->isStateLazyLoaded()) {
            $targets[$entry->getName()] = ['entry' => $entry->getKey()];
        }
    }

    $page->call('callSchemaComponentMethod', $targets['quote_count']['entry'], 'loadState', [$targets])
        ->assertReturned(function (array $entries): bool {
            expect($entries)->toHaveKeys(['quote_count', 'quote_amount', 'quote_items', 'so_count', 'so_amount', 'so_items'])
                ->and(strip_tags($entries['quote_count']))->toContain('1')
                ->and(strip_tags($entries['quote_amount']))->toContain('1,234.50')
                ->and(strip_tags($entries['so_amount']))->toContain('0.00');

            return true;
        });
});
