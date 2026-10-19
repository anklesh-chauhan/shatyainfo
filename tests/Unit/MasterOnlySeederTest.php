<?php

use App\Models\AccountMaster;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PharmaAccountReferenceSeeder;
use Database\Seeders\TypeMasterSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('pharma account references preserve existing and retired masters on repeat runs', function () {
    config(['database.connections.pharma_master_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pharma_master_test');

    try {
        foreach (['type_masters', 'rating_types', 'industry_types'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->string('name');
                if ($tableName === 'type_masters') {
                    $table->string('typeable_type');
                    $table->unsignedBigInteger('parent_id')->nullable();
                    $table->string('description')->nullable();
                }
                $table->timestamps();
                $table->softDeletes();
            });
        }

        DB::table('rating_types')->insert(['name' => 'Active']);
        DB::table('rating_types')->insert(['name' => 'C - Low Potential', 'deleted_at' => now()]);
        $seeder = new PharmaAccountReferenceSeeder;
        $seeder->run();
        $before = collect(['type_masters', 'rating_types', 'industry_types'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()]);
        $seeder->run();

        foreach ($before as $table => $rows) {
            expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($rows);
        }
        expect(DB::table('rating_types')->where('name', 'Active')->count())->toBe(1)
            ->and(DB::table('rating_types')->where('name', 'C - Low Potential')->whereNotNull('deleted_at')->count())->toBe(1)
            ->and(DB::table('type_masters')->where('name', 'Doctor')->value('typeable_type'))->toBe(AccountMaster::class)
            ->and(DB::table('industry_types')->where('name', 'Pharmaceuticals')->exists())->toBeTrue()
            ->and(DB::table('type_masters')->whereIn('name', ['Veterinarian', 'Poultry Farm', 'Dairy Farm'])->exists())->toBeFalse()
            ->and(DB::table('industry_types')->whereIn('name', ['Animal Health / Veterinary', 'Poultry', 'Dairy', 'Livestock'])->exists())->toBeFalse();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('pharma_master_test');
    }
});

test('default seeding excludes demo and business records but retains reference masters', function () {
    $seeder = new class extends DatabaseSeeder
    {
        public array $selected = [];

        public function call($class, $silent = false, array $parameters = []): static
        {
            $this->selected = (array) $class;

            return $this;
        }
    };
    $seeder->run();

    foreach (['EmpDepartmentSeeder', 'EmpGradeSeeder', 'EmpJobTitleSeeder', 'JobRoleSeeder'] as $excluded) {
        expect($seeder->selected)->not->toContain('Database\\Seeders\\'.$excluded);
    }

    foreach (['UsersTableSeeder', 'SalesUsersSeeder', 'FullDemoSeeder', 'LeaveDemoDataSeeder', 'AccountMasterSeeder', 'ItemMasterSeeder', 'ItemBrandSeeder', 'ItemCategorySeeder', 'LocationMasterSeeder', 'TerritorySeeder', 'OrganizationalUnitSeeder', 'PositionSeeder', 'PositionEmployeeMapperSeeder', 'PatchSeeder', 'StandardFareChartSeeder', 'OrganizationSeeder', 'AccountTypeSeeder', 'ChartOfAccountSeeder'] as $excluded) {
        expect($seeder->selected)->not->toContain('Database\\Seeders\\'.$excluded);
    }
    foreach (['ConfigDrivenShieldPermissionSeeder', 'GeographicReferenceSeeder', 'TypeMasterSeeder', 'PaymentTermsSeeder', 'PaymentMethodsSeeder', 'VisitTypeAndPurposeSeeder', 'ExpensePolicySeeder'] as $required) {
        expect($seeder->selected)->toContain('Database\\Seeders\\'.$required);
    }
});

test('type masters are deterministic and do not duplicate when seeded again', function () {
    config(['database.connections.master_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('master_test');
    try {
        Schema::create('type_masters', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('typeable_type');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $seeder = new TypeMasterSeeder;
        $seeder->run();
        $before = DB::table('type_masters')->get()->toArray();
        $seeder->run();
        $after = DB::table('type_masters')->get()->toArray();
        expect($before)->not->toBeEmpty()->and($after)->toEqual($before);
        foreach ($after as $type) {
            expect($type->description)->toBe($type->name);
        }
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('master_test');
    }
});
