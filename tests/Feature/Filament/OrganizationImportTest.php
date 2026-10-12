<?php

use App\Filament\Imports\OrganizationImporter;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Models\IndustryType;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('imports organization profiles and creates a named parent', function () {
    $user = reportingUser('OrgImporter');
    IndustryType::query()->create(['name' => 'Pharmaceuticals']);

    importOrganizationRows($user, [
        [
            'name' => 'Acme Pharma Pvt Ltd',
            'display_name' => 'Acme Pharma',
            'website' => 'https://acme-pharma.test',
            'founded_at' => '2012-04-01',
            'industryType' => 'Pharmaceuticals',
            'email' => 'hello@acme-pharma.test',
            'phone' => '+91-11-4000-1000',
            'fax' => '+91-11-4000-1001',
            'contact_person' => 'Anita Shah',
            'contact_person_email' => 'anita@acme-pharma.test',
            'contact_person_phone' => '+91-98100-10000',
            'legal_name' => 'Acme Pharma Private Limited',
            'registration_number' => 'U24230MH2012PTC123456',
            'gst_number' => '27AAAAA0000A1Z5',
            'registration_date' => '2012-04-01',
            'legal_status' => 'Private Limited',
            'parent_organization' => 'Acme Group',
            'size' => '500-1000',
            'annual_revenue' => '2500000.50',
            'operation_hours' => 'Mon-Sat, 9 AM - 6 PM',
            'timezone' => 'Asia/Kolkata',
            'language' => 'English',
            'linkedin_url' => 'https://linkedin.com/company/acme-pharma',
            'twitter_url' => 'https://twitter.com/acmepharma',
            'facebook_url' => 'https://facebook.com/acmepharma',
            'instagram_url' => 'https://instagram.com/acmepharma',
            'status' => 'Active',
            'description' => 'Field force organization.',
            'metadata' => '{"code":"ACME"}',
        ],
        [
            'name' => 'Acme Group',
            'email' => 'group@acme-pharma.test',
            'status' => 'active',
        ],
    ]);

    $organization = Organization::query()->where('name', 'Acme Pharma Pvt Ltd')->first();
    $parent = Organization::query()->where('name', 'Acme Group')->first();

    expect($organization)->not->toBeNull()
        ->and($parent)->not->toBeNull()
        ->and($organization->parent_organization_id)->toBe($parent->id)
        ->and($organization->display_name)->toBe('Acme Pharma')
        ->and($organization->email)->toBe('hello@acme-pharma.test')
        ->and($organization->gst_number)->toBe('27AAAAA0000A1Z5')
        ->and($organization->status)->toBe('active')
        ->and($organization->timezone)->toBe('Asia/Kolkata')
        ->and($organization->metadata)->toBe(['code' => 'ACME'])
        ->and((string) $organization->annual_revenue)->toBe('2500000.50')
        ->and($organization->founded_at->toDateString())->toBe('2012-04-01')
        ->and($organization->industryType->name)->toBe('Pharmaceuticals')
        ->and($organization->created_by)->toBe($user->id)
        ->and($parent->email)->toBe('group@acme-pharma.test')
        ->and(Organization::query()->count())->toBe(2);
});

it('updates an organization matched by name', function () {
    $user = reportingUser('OrgUpdater');

    importOrganizationRows($user, [[
        'name' => 'Acme Pharma Pvt Ltd',
        'email' => 'old@acme-pharma.test',
    ]]);

    importOrganizationRows($user, [[
        'name' => 'Acme Pharma Pvt Ltd',
        'email' => 'new@acme-pharma.test',
        'phone' => '+91-11-4000-2000',
    ]]);

    expect(Organization::query()->count())->toBe(1)
        ->and(Organization::query()->first())
        ->email->toBe('new@acme-pharma.test')
        ->phone->toBe('+91-11-4000-2000');
});

it('rejects an organization that names itself as parent', function () {
    $user = reportingUser('OrgSelfParent');

    expect(fn () => importOrganizationRows($user, [[
        'name' => 'Acme Pharma',
        'parent_organization' => 'Acme Pharma',
    ]]))->toThrow(ValidationException::class);

    expect(Organization::query()->count())->toBe(0);
});

it('rejects an unknown industry type', function () {
    $user = reportingUser('OrgIndustry');

    expect(fn () => importOrganizationRows($user, [[
        'name' => 'Acme Pharma',
        'industryType' => 'Not A Real Industry',
    ]]))->toThrow(ValidationException::class);

    expect(Organization::query()->count())->toBe(0);
});

it('rejects metadata that is not a json object', function () {
    $user = reportingUser('OrgMetadata');

    expect(fn () => importOrganizationRows($user, [[
        'name' => 'Acme Pharma',
        'metadata' => 'not-json',
    ]]))->toThrow(ValidationException::class);

    expect(Organization::query()->count())->toBe(0);
});

it('shows the organization import action to users who can create organizations', function () {
    $user = reportingUser('OrgList');
    grantOrganizationPermissions($user, ['ViewAny:Organization', 'Create:Organization']);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ListOrganizations::class)
        ->assertActionExists('import')
        ->assertActionVisible('import');
});

it('hides the organization import action without create permission', function () {
    $user = reportingUser('OrgListDenied');
    grantOrganizationPermissions($user, ['ViewAny:Organization']);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ListOrganizations::class)
        ->assertActionHidden('import');
});

/**
 * @param  array<int, array<string, string>>  $rows
 */
function importOrganizationRows(User $user, array $rows): void
{
    $import = Import::query()->create([
        'file_name' => 'organizations.csv',
        'file_path' => 'organizations.csv',
        'importer' => OrganizationImporter::class,
        'total_rows' => count($rows),
        'user_id' => $user->id,
    ]);

    $columnMap = [];

    foreach (OrganizationImporter::getColumns() as $column) {
        /** @var ImportColumn $column */
        $columnMap[$column->getName()] = $column->getName();
    }

    auth()->login($user);

    $importer = $import->getImporter($columnMap, []);

    foreach ($rows as $row) {
        $importer($row);
    }
}

/**
 * @param  array<int, string>  $permissions
 */
function grantOrganizationPermissions(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
}
