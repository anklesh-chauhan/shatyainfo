<?php

use App\Enums\FieldPlanningMode;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
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

it('shows open workspace screens to a user with no role', function () {
    $user = reportingUser('Navigation No Role');
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/mobile-navigation')->assertSuccessful();
    $keys = itemKeys($response->json('groups'));

    expect(array_keys($response->json()))->toBe(['schema_version', 'context', 'home', 'groups'])
        ->and($response->json('schema_version'))->toBe(1)
        ->and($response->json('context.user_id'))->toBe($user->id)
        ->and($response->json('context.company_key'))->toBe(Tenant::current() ? 'tenant:'.Tenant::current()->getKey() : 'landlord')
        ->and($response->json('home'))->toBe('todays-tour')
        ->and($response->json('groups.0.key'))->toBe('workspace')
        ->and($keys)->toBe(['g01-03', 'g01-06'])
        ->and($response->json())->not->toHaveKey('actions')
        ->and(array_column($response->json('groups'), 'key'))->not->toContain('settings');
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
    Sanctum::actingAs($user);

    $keys = itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups'));

    expect($keys)->toContain('g02-03', 'g06-03')
        ->and($keys)->not->toContain('g02-06', 'g06-07', 'g07-02', 'g07-06', 'g07-07', 'g07-08');
});

it('does not turn record visibility into menu or action access', function () {
    $user = reportingUser('Navigation Scope');
    grantPermission($user, 'AccessAllRecords');
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/mobile-navigation')->assertSuccessful();

    expect(itemKeys($response->json('groups')))->toBe(['g01-03', 'g01-06'])
        ->and($response->json())->not->toHaveKey('actions')
        ->and(array_column($response->json('groups'), 'key'))->not->toContain('settings');
});

it('reuses the punch page role check', function () {
    $user = reportingUser('Navigation Punch');
    Sanctum::actingAs($user);
    expect(itemKeys($this->getJson('/api/v1/mobile-navigation')->json('groups')))->not->toContain('g01-02');

    $user->assignRole(Role::findOrCreate('marketing', 'web'));
    $keys = itemKeys($this->getJson('/api/v1/mobile-navigation')->assertSuccessful()->json('groups'));

    expect($keys)->toContain('g01-02')
        ->and($keys[0])->toBe('g01-02');
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
