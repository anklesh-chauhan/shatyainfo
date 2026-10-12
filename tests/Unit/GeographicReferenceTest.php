<?php

use App\Support\Tenancy\GeographicReference;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

function geographicTestReference(): GeographicReference
{
    return new class extends GeographicReference
    {
        protected function connection(): Connection
        {
            return DB::connection('geo_test');
        }
    };
}

beforeEach(function () {
    config(['multitenancy.geographic_reference_database' => 'reference_test', 'database.connections.geo_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $db = DB::connection('geo_test');
    $db->statement("ATTACH DATABASE ':memory:' AS reference_test");
    $db->statement("ATTACH DATABASE ':memory:' AS tenant_test");
    $db->getPdo()->sqliteCreateFunction('NOW', fn () => '2026-10-12 00:00:00');
    $definitions = [
        'countries' => 'id INTEGER PRIMARY KEY, name TEXT',
        'states' => 'id INTEGER PRIMARY KEY, name TEXT, country_id INTEGER, gst_code TEXT',
        'cities' => 'id INTEGER PRIMARY KEY, name TEXT, state_id INTEGER, country_id INTEGER, city_class_id INTEGER DEFAULT NULL, is_hill_station INTEGER DEFAULT 0',
        'city_pin_codes' => 'id INTEGER PRIMARY KEY, pin_code INTEGER, area_town TEXT, city_id INTEGER, state_id INTEGER, country_id INTEGER, latitude REAL, longitude REAL',
    ];
    foreach (['reference_test', 'tenant_test'] as $schema) {
        foreach ($definitions as $table => $definition) {
            $db->statement("CREATE TABLE {$schema}.{$table} ({$definition}, created_by INTEGER DEFAULT NULL, updated_by INTEGER DEFAULT NULL, created_at TEXT, updated_at TEXT)");
        }
    }
    $db->statement('CREATE TABLE reference_test.geographic_manifest (id INTEGER PRIMARY KEY, version INTEGER)');
    $db->table('reference_test.geographic_manifest')->insert(['id' => 1, 'version' => 1]);
    $db->table('reference_test.countries')->insert(['id' => 11, 'name' => 'India', 'created_by' => 999]);
    $db->table('reference_test.states')->insert(['id' => 22, 'name' => 'Gujarat', 'country_id' => 11, 'gst_code' => '24']);
    $db->table('reference_test.cities')->insert(['id' => 33, 'name' => 'Ahmedabad', 'state_id' => 22, 'country_id' => 11, 'city_class_id' => 999, 'is_hill_station' => 1]);
    $db->table('reference_test.city_pin_codes')->insert(['id' => 44, 'pin_code' => 380001, 'area_town' => 'City centre', 'city_id' => 33, 'state_id' => 22, 'country_id' => 11, 'latitude' => 23.02, 'longitude' => 72.57]);
});

test('bulk geography copying preserves IDs and excludes tenant-specific fields', function () {
    $reference = geographicTestReference();
    expect($reference->validate())->toBe(['countries' => 1, 'states' => 1, 'cities' => 1, 'city_pin_codes' => 1]);
    expect($reference->copyToDatabase('tenant_test'))->toBe(['countries' => 1, 'states' => 1, 'cities' => 1, 'city_pin_codes' => 1]);
    $db = DB::connection('geo_test');
    expect($db->table('tenant_test.countries')->value('id'))->toBe(11)
        ->and($db->table('tenant_test.countries')->value('created_by'))->toBeNull()
        ->and($db->table('tenant_test.cities')->value('city_class_id'))->toBeNull()
        ->and($db->table('tenant_test.cities')->value('is_hill_station'))->toBe(0)
        ->and($db->table('tenant_test.city_pin_codes')->value('city_id'))->toBe(33)
        ->and($db->table('tenant_test.city_pin_codes')->value('id'))->toBe(44);
});

test('bulk geography copying refuses populated destinations and unsafe identifiers', function () {
    $reference = geographicTestReference();
    $reference->copyToDatabase('tenant_test');
    expect(fn () => $reference->copyToDatabase('tenant_test'))->toThrow(RuntimeException::class)
        ->and(fn () => $reference->copyToDatabase('tenant_test; DROP DATABASE landlord'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $reference->copyToDatabase('reference_test'))->toThrow(RuntimeException::class);
    expect(DB::connection('geo_test')->table('tenant_test.countries')->count())->toBe(1);
});

test('geographic validation rejects orphaned or inconsistent parent links', function () {
    $db = DB::connection('geo_test');
    $db->table('reference_test.city_pin_codes')->update(['city_id' => 555]);
    expect(fn () => geographicTestReference()->validate())->toThrow(RuntimeException::class);
    $db->table('reference_test.city_pin_codes')->update(['city_id' => 33, 'state_id' => 22, 'country_id' => 777]);
    expect(fn () => geographicTestReference()->validate())->toThrow(RuntimeException::class);
});

test('bulk geography copying requires a completed reference dataset', function () {
    DB::connection('geo_test')->table('reference_test.geographic_manifest')->delete();
    expect(fn () => geographicTestReference()->copyToDatabase('tenant_test'))->toThrow(RuntimeException::class);
});

test('failed geographic copying rolls back all destination tables', function () {
    $db = DB::connection('geo_test');
    $db->statement("CREATE TRIGGER tenant_test.fail_city BEFORE INSERT ON cities BEGIN SELECT RAISE(ABORT, 'Simulated copy failure'); END");
    expect(fn () => geographicTestReference()->copyToDatabase('tenant_test'))->toThrow(QueryException::class);
    foreach (array_keys(GeographicReference::COLUMNS) as $table) {
        expect($db->table('tenant_test.'.$table)->count())->toBe(0);
    }
});

afterEach(function () {
    DB::purge('geo_test');
});
