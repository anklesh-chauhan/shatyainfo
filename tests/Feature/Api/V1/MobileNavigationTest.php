<?php

use App\Enums\FieldPlanningMode;
use App\Filament\Pages\Reports\DcrRegister;
use App\Filament\Resources\AccountMasters\AccountMasterResource;
use App\Filament\Resources\Approvals\ApprovalResource;
use App\Filament\Resources\LeaveBalances\LeaveBalanceResource;
use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use App\Services\Mobile\MobileNavigationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('requires authentication and leaves the profile response unchanged', function () {
    $this->getJson('/api/v1/mobile-navigation')->assertUnauthorized();

    $user = reportingUser('Navigation Profile');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonMissingPath('data.groups');
});

it('shows no workspace screens to a user with no role', function () {
    $user = reportingUser('Navigation No Role');
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/dashboard/today')->assertForbidden();
    $this->getJson('/api/v1/todays-tour')->assertForbidden();
    $this->getJson('/api/v1/todays-priorities')->assertForbidden();
    $this->postJson('/api/v1/attendance/punch-in', ['latitude' => 19.0, 'longitude' => 72.0])->assertForbidden();

    $response = $this->getJson('/api/v1/mobile-navigation')->assertSuccessful();
    $keys = itemKeys($response->json('groups'));

    expect(array_keys($response->json()))->toBe(['schema_version', 'context', 'home', 'groups'])
        ->and($response->json('schema_version'))->toBe(1)
        ->and($response->json('context.user_id'))->toBe($user->id)
        ->and($response->json('context.company_key'))->toBe(Tenant::current() ? 'tenant:'.Tenant::current()->getKey() : 'landlord')
        ->and($response->json('home'))->toBe('modules')
        ->and($keys)->toBe([])
        ->and($response->json())->not->toHaveKey('actions')
        ->and(array_column($response->json('groups'), 'key'))->not->toContain('settings');
});

it('negotiates standalone destinations without changing the original screen keys', function () {
    $user = grantPagePermissions(fieldReportingUser('Standalone Navigation'),
        'View:PunchIn', 'ViewAny:EDetailingMaterial', 'ViewAny:SgipDistribution');
    Sanctum::actingAs($user);

    $legacy = collect($this->getJson('/api/v1/mobile-navigation')->assertOk()
        ->json('groups'))->flatMap(fn (array $group): array => $group['items'])->keyBy('key');
    $response = $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonPath('schema_version', 1)->assertJsonPath('home', 'modules');
    $items = collect($response->json('groups'))->flatMap(fn (array $group): array => $group['items'])->keyBy('key');

    foreach ([
        'g01-02' => 'punch',
        'g02-03' => 'visit-register',
        'g02-06' => 'expense-register',
        'g06-03' => 'e-detailing-library',
        'g06-07' => 'sgip-register',
    ] as $item => $screen) {
        expect($legacy[$item])->not->toHaveKey('standalone_screen_key')
            ->and($items[$item]['screen_key'])->toBe($legacy[$item]['screen_key'])
            ->and($items[$item]['standalone_screen_key'])->toBe($screen);
    }
});

it('shares ordered quick links without replacing saved Admin-only entries', function () {
    $user = grantPagePermissions(fieldReportingUser('Quick Links Rep'), 'ViewAny:AccountMaster');
    $other = fieldReportingUser('Unrelated Quick Links');
    $visits = VisitResource::class;
    $accounts = AccountMasterResource::class;
    $adminOnly = ApprovalResource::class;
    $user->forceFill(['topbar_links' => [$visits, $adminOnly, $accounts]])->save();

    $this->getJson('/api/v1/mobile-quick-links')->assertUnauthorized();
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/mobile-navigation')->assertJsonMissingPath('quick_links');
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonPath('quick_links', true);
    $this->getJson('/api/v1/mobile-quick-links')->assertOk()
        ->assertJsonPath('data.selected', ['g02-03', 'g03-01'])
        ->assertJsonPath('data.limit', 7);
    $this->patchJson('/api/v1/mobile-quick-links', ['links' => ['https://example.test']])
        ->assertUnprocessable()->assertJsonValidationErrors('links');
    $this->patchJson('/api/v1/mobile-quick-links', ['links' => ['g03-01', 'g03-01']])->assertUnprocessable();
    $this->patchJson('/api/v1/mobile-quick-links', ['links' => ['g03-01', 'g02-03']])
        ->assertOk()->assertJsonPath('data.selected', ['g03-01', 'g02-03']);
    expect($user->fresh()->topbar_links)->toBe([$accounts, $adminOnly, $visits]);

    $user->revokePermissionTo('ViewAny:AccountMaster');
    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/v1/mobile-quick-links')->assertJsonPath('data.selected', ['g02-03']);
    $this->patchJson('/api/v1/mobile-quick-links', ['links' => ['g03-01']])->assertUnprocessable();
    $this->patchJson('/api/v1/mobile-quick-links', ['links' => []])->assertOk()->assertJsonPath('data.selected', []);
    expect($user->fresh()->topbar_links)->toBe([$accounts, $adminOnly]);

    $this->patchJson('/api/v1/mobile-quick-links', ['links' => null])->assertOk();
    expect($user->fresh()->topbar_links)->toBeNull()
        ->and($other->fresh()->topbar_links)->toBeNull();
});

it('uses Admin cluster parents for HR and reports', function () {
    $registry = app(MobileNavigationRegistry::class);
    $leave = $registry->parentFor(LeaveBalanceResource::class);
    $report = $registry->parentFor(DcrRegister::class);
    expect($leave['key'])->toBe('leave-management')->and($leave['sort'])->toBe(30)
        ->and($report['key'])->toBe('all-reports')->and($report['sort'])->toBe(3);
});

it('accounts for eligible Admin destinations through bindings and the remaining delivery inventory', function () {
    $user = reportingUser('Navigation Inventory Admin');
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $panel = Filament\Facades\Filament::getPanel('admin');
    Filament\Facades\Filament::setCurrentPanel($panel);
    $registry = app(MobileNavigationRegistry::class);
    $bindings = array_column($registry->entries(), 'source');
    $groups = array_column($registry->groups(), 'label');
    $inventory = file_get_contents(base_path('docs/mobile-parity-inventory.md'));

    $gaps = [];
    foreach ([...$panel->getPages(), ...$panel->getResources()] as $source) {
        $parent = $registry->parentFor($source);
        $group = $parent === null ? $source::getNavigationGroup() : $parent['source']::getNavigationGroup();
        if (! in_array($group, $groups, true) || ! $source::shouldRegisterNavigation() || ! $source::canAccess()) {
            continue;
        }

        if (! in_array($source, $bindings, true) && ! str_contains($inventory, class_basename($source))) {
            $gaps[] = $source;
        }
    }

    $this->assertSame([], $gaps, 'Eligible Admin destinations need an implemented binding or a documented delivery gap.');
});

it('lists only the callers visits and denies registers without permission', function () {
    $user = fieldReportingUser('Register Owner');
    $other = fieldReportingUser('Other Visits');
    $territory = reportingTerritory('Register visits');
    $patch = reportingPatch($territory, 'Register patch');
    $visit = fn (User $owner): Visit => Visit::withoutEvents(fn (): Visit => Visit::create([
        'employee_id' => $owner->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(), 'visit_type' => 'planned', 'visit_status' => 'draft',
        'document_number' => 'REGISTER-'.$owner->id,
    ]));
    $owned = $visit($user);
    $visit($other);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/visits')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $owned->id);
    $this->getJson('/api/v1/dcr-expenses')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/sgip-distributions')->assertForbidden();

    Sanctum::actingAs(reportingUser('No Register Permissions'));
    $this->getJson('/api/v1/visits')->assertForbidden();
    $this->getJson('/api/v1/dcr-expenses')->assertForbidden();
});

it('shows accounts only when the admin resource allows them', function () {
    $denied = reportingUser('Navigation Denied');
    $allowed = reportingUser('Navigation Allowed');
    grantPermission($allowed, 'ViewAny:AccountMaster');

    Sanctum::actingAs($denied);
    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->not->toContain('g03-01');

    Sanctum::actingAs($allowed);
    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->toContain('g03-01');
});

it('hides module resources while leaving unrelated screens available', function () {
    Organization::query()->create([
        'name' => 'Navigation Modules',
        'metadata' => ['company_setup' => ['organization_saved' => true]],
    ]);
    $user = reportingUser('Navigation Modules');
    grantPermission($user, 'ViewAny:Visit');
    grantPermission($user, 'ViewAny:SalesDcrExpense');
    grantPermission($user, 'ViewAny:EDetailingMaterial');
    grantPermission($user, 'ViewAny:SgipDistribution');
    grantPermission($user, 'View:PunchIn');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', ['latitude' => 19.0, 'longitude' => 72.0])->assertForbidden();

    $keys = itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups'));

    expect($keys)->toContain('g02-03', 'g06-03')
        ->and($keys)->not->toContain('g01-02', 'g02-06', 'g06-07', 'g07-02', 'g07-06', 'g07-07', 'g07-08');
});

it('enforces disabled company modules on direct API routes', function () {
    Organization::query()->create([
        'name' => 'Disabled Modules',
        'metadata' => ['company_setup' => ['organization_saved' => true]],
    ]);
    $user = grantPagePermissions(reportingUser('Module Viewer'), 'ViewAny:Lead', 'ViewAny:Deal',
        'View:PunchIn', 'ViewAny:LeaveBalance', 'ViewAny:SgipDistribution');
    Sanctum::actingAs($user);

    foreach (['leads', 'deals', 'sample-ledger', 'attendance/today', 'leave-types'] as $endpoint) {
        $this->getJson('/api/v1/'.$endpoint)->assertForbidden();
    }

    expect(LeaveBalanceResource::canViewAny())->toBeFalse()
        ->and(SgipDistributionResource::canViewAny())->toBeFalse();
});

it('does not turn record visibility into menu or action access', function () {
    $user = reportingUser('Navigation Scope');
    grantPermission($user, 'AccessAllRecords');
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/mobile-navigation')->assertSuccessful();

    expect(itemKeys($response->json('groups')))->toBe([])
        ->and($response->json())->not->toHaveKey('actions')
        ->and(array_column($response->json('groups'), 'key'))->not->toContain('settings');
});

it('updates workspace access when an assigned permission is revoked', function () {
    $user = reportingUser('Navigation Workspace');
    $role = Role::findOrCreate('workspace_test', 'web');
    $role->givePermissionTo(Permission::findOrCreate('View:TodaysTour', 'web'));
    $role->givePermissionTo(Permission::findOrCreate('View:PunchIn', 'web'));
    $user->assignRole($role);
    Sanctum::actingAs($user);

    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->toContain('g01-02', 'g01-03');
    $this->getJson('/api/v1/todays-tour')->assertSuccessful();

    $role->revokePermissionTo('View:TodaysTour');
    Sanctum::actingAs($user->fresh());

    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->toContain('g01-02')->not->toContain('g01-03');
    $this->getJson('/api/v1/todays-tour')->assertForbidden();
});

it('removes both DCR screen entries when report detail access is revoked', function () {
    $user = fieldReportingUser('DCR Role Revision');
    Sanctum::actingAs($user);
    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->toContain('g02-04', 'g02-06');

    $user->revokePermissionTo('View:SalesDcr');
    Sanctum::actingAs($user->fresh());
    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups')))
        ->not->toContain('g02-04', 'g02-06');
    $this->getJson('/api/v1/dcrs/today')->assertForbidden();
});

it('hides tour plans when the active planning method does not support them', function () {
    $user = reportingUser('Navigation Planning');
    grantPermission($user, 'Manage:FieldPlanningPreference');
    grantPermission($user, 'ViewAny:SalesTourPlan');
    grantPermission($user, 'ViewAny:AccountMaster');
    Sanctum::actingAs($user);
    $settings = app(FieldPlanningSettingsService::class);
    $revision = $settings->saveProposal($user, [
        'mode' => FieldPlanningMode::DirectDcr->value,
        'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::DirectDcr),
        'reason' => 'Hide monthly tour plans',
        'proposed_effective_on' => null,
    ], 0);
    $settings->activate($user, $revision->id, 1);

    $keys = itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups'));

    expect($keys)->toContain('g03-01')
        ->not->toContain('g02-01', 'g02-02');
});

function grantPermission(User $user, string $permission): void
{
    $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
}

/**
 * @param  list<array{items: list<array{key: string}>}>  $groups
 * @return list<string>
 */
function itemKeys(array $groups): array
{
    $keys = [];

    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = $item['key'];
        }
    }

    return $keys;
}
