<?php

namespace App\Support\Tenancy;

use Database\Seeders\CitySeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeographicReference
{
    public const COLUMNS = [
        'countries' => ['id', 'name'],
        'states' => ['id', 'name', 'country_id', 'gst_code'],
        'cities' => ['id', 'name', 'state_id', 'country_id'],
        'city_pin_codes' => ['id', 'pin_code', 'area_town', 'city_id', 'state_id', 'country_id', 'latitude', 'longitude'],
    ];

    protected function connection(): Connection
    {
        return DB::connection('geographic_reference');
    }

    public function initialize(): array
    {
        $configuration = config('database.connections.geographic_reference');
        $name = $configuration['database'];
        $provisioning = DB::connection('pgsql_provisioning');
        if (in_array($name, ['postgres', 'template0', 'template1', $provisioning->getDatabaseName(), DB::connection('pgsql')->getDatabaseName()], true)) {
            throw new RuntimeException('The geographic reference database must be separate from application databases.');
        }
        if (! $provisioning->table('pg_database')->where('datname', $name)->exists()) {
            $identifiers = app(TenantDatabaseProvisioner::class);
            $database = $identifiers->quoteIdentifier($name);
            $owner = $identifiers->quoteIdentifier($configuration['username']);
            try {
                $provisioning->statement("CREATE DATABASE {$database} OWNER {$owner} ENCODING 'UTF8'");
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '42P04') {
                    throw $exception;
                }
            }
        }

        $db = $this->connection();
        $originalConnection = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection($db->getName());

            return $db->transaction(function () use ($db): array {
                if (! $db->scalar('SELECT pg_try_advisory_xact_lock(?, ?)', [77219, 1])) {
                    throw new RuntimeException('Another geographic reference initialization is running.');
                }

                if ($db->getSchemaBuilder()->hasTable('geographic_manifest')
                    && $db->table('geographic_manifest')->where('id', 1)->where('version', 1)->exists()) {
                    return $this->validate($db);
                }

                $this->createSchema($db);
                foreach ([CountrySeeder::class, StateSeeder::class, CitySeeder::class] as $class) {
                    (new $class)->setContainer(app())->run();
                }
                $corrected = $db->affectingStatement('UPDATE cities SET country_id = states.country_id FROM states WHERE states.id = cities.state_id AND cities.country_id <> states.country_id');
                Log::info('Geographic city parent normalization.', ['corrected' => $corrected]);
                $this->advanceSequence($db, 'states');
                $this->importAreaTowns();
                $counts = $this->validate($db);
                $db->table('geographic_manifest')->insert(['id' => 1, 'version' => 1, 'updated_at' => now()]);

                return $counts;
            });
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
    }

    private function createSchema(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $schema->create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        $schema->create('states', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('country_id')->constrained();
            $table->string('gst_code', 10)->nullable();
            $table->timestamps();
        });
        $schema->create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('state_id')->constrained();
            $table->foreignId('country_id')->constrained();
            $table->timestamps();
        });
        $schema->create('city_pin_codes', function (Blueprint $table): void {
            $table->id();
            $table->integer('pin_code');
            $table->string('area_town');
            $table->foreignId('city_id')->constrained();
            $table->foreignId('state_id')->constrained();
            $table->foreignId('country_id')->constrained();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
        $schema->create('geographic_manifest', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('version');
            $table->timestamp('updated_at');
        });
    }

    protected function importAreaTowns(): void
    {
        $handle = fopen(storage_path('CityPinCodeSeeder.csv'), 'r');
        if ($handle === false) {
            throw new RuntimeException('The geographic CSV could not be opened.');
        }
        $seen = [];
        $batch = [];
        $line = 1;
        $cities = DB::table('cities')->select('id', 'state_id', 'country_id')->get()->keyBy('id');
        $corrected = 0;
        try {
            fgetcsv($handle, escape: '');
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== 5 || ! preg_match('/^[1-9][0-9]{5}$/', trim($values[0]))) {
                    throw new RuntimeException('Invalid area/town CSV row '.$line.'.');
                }
                $row = array_combine(['pin_code', 'area_town', 'city_id', 'state_id', 'country_id'], array_map('trim', $values));
                $city = $cities->get((int) $row['city_id']);
                if (! $city) {
                    throw new RuntimeException('Unknown city in area/town CSV row '.$line.'.');
                }
                if ((int) $row['state_id'] !== (int) $city->state_id || (int) $row['country_id'] !== (int) $city->country_id) {
                    $corrected++;
                }
                $row['state_id'] = $city->state_id;
                $row['country_id'] = $city->country_id;
                $key = strtolower(implode('|', $row));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $row['created_at'] = $row['updated_at'] = now();
                $batch[] = $row;
                if (count($batch) === 2000) {
                    DB::table('city_pin_codes')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DB::table('city_pin_codes')->insert($batch);
            }
            Log::info('Geographic area parent normalization.', ['corrected' => $corrected]);
        } finally {
            fclose($handle);
        }
    }

    public function validate(?Connection $connection = null): array
    {
        $db = $connection ?? $this->connection();
        $counts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $counts[$table] = $db->table($table)->count();
            if ($counts[$table] === 0) {
                throw new RuntimeException('Geographic reference '.$table.' is empty. Run geography:init first.');
            }
        }
        foreach ([['states', 'country_id', 'countries'], ['cities', 'state_id', 'states'], ['cities', 'country_id', 'countries'], ['city_pin_codes', 'city_id', 'cities'], ['city_pin_codes', 'state_id', 'states'], ['city_pin_codes', 'country_id', 'countries']] as [$table, $column, $parent]) {
            $missing = $db->selectOne("SELECT COUNT(*) AS total FROM {$table} child LEFT JOIN {$parent} parent ON parent.id = child.{$column} WHERE child.{$column} IS NULL OR parent.id IS NULL")->total;
            if ($missing > 0) {
                throw new RuntimeException("Geographic reference {$table} contains {$missing} missing {$parent} references.");
            }
        }
        $invalid = $db->selectOne("SELECT COUNT(*) AS total FROM city_pin_codes WHERE pin_code < 100000 OR pin_code > 999999 OR area_town IS NULL OR TRIM(area_town) = ''")->total;
        if ($invalid > 0) {
            throw new RuntimeException('Geographic reference contains invalid PIN codes or blank area/town names.');
        }
        $inconsistent = $db->selectOne('SELECT COUNT(*) AS total FROM cities city JOIN states state ON state.id = city.state_id WHERE city.country_id <> state.country_id')->total;
        $inconsistent += $db->selectOne('SELECT COUNT(*) AS total FROM city_pin_codes area JOIN cities city ON city.id = area.city_id WHERE area.state_id <> city.state_id OR area.country_id <> city.country_id')->total;
        if ($inconsistent > 0) {
            throw new RuntimeException('Geographic reference contains inconsistent country, state or city relationships.');
        }

        return $counts;
    }

    public function copyTo(Connection $destination): array
    {
        $source = $this->connection();
        if ($destination === $source || ($source->getDatabaseName() !== ':memory:'
            && $destination->getDatabaseName() === $source->getDatabaseName())) {
            throw new RuntimeException('Cannot copy geographic reference data into itself.');
        }
        if (! $source->getSchemaBuilder()->hasTable('geographic_manifest')
            || ! $source->table('geographic_manifest')->where('id', 1)->where('version', 1)->exists()) {
            throw new RuntimeException('Geographic reference data is not ready. Run php artisan geography:init first.');
        }

        $start = microtime(true);
        $counts = $destination->transaction(function () use ($source, $destination): array {
            if ($destination->getDriverName() === 'pgsql') {
                $destination->statement('LOCK TABLE '.implode(', ', array_keys(self::COLUMNS)).' IN SHARE ROW EXCLUSIVE MODE');
            }

            foreach (array_keys(self::COLUMNS) as $table) {
                if ($destination->table($table)->exists()) {
                    throw new RuntimeException('Bulk geographic copying only supports empty destination tables. Existing company data is preserved.');
                }
            }

            $counts = [];
            $timestamp = now();
            foreach (self::COLUMNS as $table => $columns) {
                $counts[$table] = 0;
                $source->table($table)->select($columns)->chunkById(1000, function ($rows) use ($destination, $table, $timestamp, &$counts): void {
                    $destination->table($table)->insert($rows->map(fn ($row): array => [
                        ...(array) $row, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ])->all());
                    $counts[$table] += $rows->count();
                });
                if ($destination->getDriverName() === 'pgsql' && $counts[$table] > 0) {
                    $this->advanceSequence($destination, $table);
                }
            }

            return $counts;
        });
        Log::info('Geographic masters bulk copied.', ['database' => $destination->getDatabaseName(), 'rows' => $counts, 'seconds' => round(microtime(true) - $start, 3)]);

        return $counts;
    }

    private function advanceSequence(Connection $db, string $table): void
    {
        $nextId = $db->table($table)->max('id') + 1;
        $sequence = $db->scalar("SELECT pg_get_serial_sequence(?, 'id')", [$table]);
        $db->statement("ALTER SEQUENCE {$sequence} RESTART WITH {$nextId}");
    }
}
