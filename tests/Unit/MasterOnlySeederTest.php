<?php

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TypeMasterSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

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
