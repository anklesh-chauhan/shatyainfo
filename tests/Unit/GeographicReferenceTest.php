<?php

use App\Support\Tenancy\GeographicReference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    foreach (['geographic_reference', 'tenant_test'] as $connection) {
        config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    }
    $definitions = [
        'countries' => 'id INTEGER PRIMARY KEY, name TEXT',
        'states' => 'id INTEGER PRIMARY KEY, name TEXT, country_id INTEGER, gst_code TEXT',
        'cities' => 'id INTEGER PRIMARY KEY, name TEXT, state_id INTEGER, country_id INTEGER, city_class_id INTEGER DEFAULT NULL, is_hill_station INTEGER DEFAULT 0',
        'city_pin_codes' => 'id INTEGER PRIMARY KEY, pin_code INTEGER, area_town TEXT, city_id INTEGER, state_id INTEGER, country_id INTEGER, latitude REAL, longitude REAL',
    ];
    foreach (['geographic_reference', 'tenant_test'] as $connection) {
        $db = DB::connection($connection);
        foreach ($definitions as $table => $definition) {
            $db->statement("CREATE TABLE {$table} ({$definition}, created_by INTEGER DEFAULT NULL, updated_by INTEGER DEFAULT NULL, created_at TEXT, updated_at TEXT)");
        }
    }
    $db = DB::connection('geographic_reference');
    $db->statement('CREATE TABLE geographic_manifest (id INTEGER PRIMARY KEY, version INTEGER)');
    $db->table('geographic_manifest')->insert(['id' => 1, 'version' => 1]);
    $db->table('countries')->insert(['id' => 11, 'name' => 'India', 'created_by' => 999]);
    $db->table('states')->insert(['id' => 22, 'name' => 'Gujarat', 'country_id' => 11, 'gst_code' => '24']);
    $db->table('cities')->insert(['id' => 33, 'name' => 'Ahmedabad', 'state_id' => 22, 'country_id' => 11, 'city_class_id' => 999, 'is_hill_station' => 1]);
    $db->table('city_pin_codes')->insert(['id' => 44, 'pin_code' => 380001, 'area_town' => 'City centre', 'city_id' => 33, 'state_id' => 22, 'country_id' => 11, 'latitude' => 23.02, 'longitude' => 72.57]);
});

test('bulk geography copying preserves IDs and excludes tenant-specific fields', function () {
    $reference = app(GeographicReference::class);
    expect($reference->validate())->toBe(['countries' => 1, 'states' => 1, 'cities' => 1, 'city_pin_codes' => 1]);
    expect($reference->copyTo(DB::connection('tenant_test')))->toBe(['countries' => 1, 'states' => 1, 'cities' => 1, 'city_pin_codes' => 1]);
    $db = DB::connection('tenant_test');
    expect($db->table('countries')->value('id'))->toBe(11)
        ->and($db->table('countries')->value('created_by'))->toBeNull()
        ->and($db->table('cities')->value('city_class_id'))->toBeNull()
        ->and($db->table('cities')->value('is_hill_station'))->toBe(0)
        ->and($db->table('city_pin_codes')->value('city_id'))->toBe(33)
        ->and($db->table('city_pin_codes')->value('id'))->toBe(44);
});

test('bulk geography copying refuses populated destinations', function () {
    $reference = app(GeographicReference::class);
    $reference->copyTo(DB::connection('tenant_test'));
    expect(fn () => $reference->copyTo(DB::connection('tenant_test')))->toThrow(RuntimeException::class)
        ->and(fn () => $reference->copyTo(DB::connection('geographic_reference')))->toThrow(RuntimeException::class);
    expect(DB::connection('tenant_test')->table('countries')->count())->toBe(1);
});

test('geographic validation rejects orphaned or inconsistent parent links', function () {
    $db = DB::connection('geographic_reference');
    $db->table('city_pin_codes')->update(['city_id' => 555]);
    expect(fn () => app(GeographicReference::class)->validate())->toThrow(RuntimeException::class);
    $db->table('city_pin_codes')->update(['city_id' => 33, 'state_id' => 22, 'country_id' => 777]);
    expect(fn () => app(GeographicReference::class)->validate())->toThrow(RuntimeException::class);
});

test('bulk geography copying requires a completed reference dataset', function () {
    DB::connection('geographic_reference')->table('geographic_manifest')->delete();
    expect(fn () => app(GeographicReference::class)->copyTo(DB::connection('tenant_test')))->toThrow(RuntimeException::class);
});

test('failed geographic copying rolls back all destination tables', function () {
    $db = DB::connection('tenant_test');
    $db->statement("CREATE TRIGGER fail_city BEFORE INSERT ON cities BEGIN SELECT RAISE(ABORT, 'Simulated copy failure'); END");
    expect(fn () => app(GeographicReference::class)->copyTo(DB::connection('tenant_test')))->toThrow(QueryException::class);
    foreach (array_keys(GeographicReference::COLUMNS) as $table) {
        expect($db->table(''.$table)->count())->toBe(0);
    }
});

afterEach(function () {
    DB::purge('geographic_reference');
    DB::purge('tenant_test');
});
