<?php

namespace App\Support\Tenancy;

use Database\Seeders\CitySeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Database\Connection;
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

    public function database(): string
    {
        return (string) config('multitenancy.geographic_reference_database', 'it_erp_reference');
    }

    public function identifier(string $name): string
    {
        return app(TenantDatabaseProvisioner::class)->quoteIdentifier($name);
    }

    protected function connection(): Connection
    {
        return DB::connection((string) config('multitenancy.landlord_database_connection_name', 'mysql'));
    }

    public function initialize(): array
    {
        $db = $this->connection();
        $lock = 'geographic_reference_'.sha1($this->database());
        if ((int) $db->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$lock])->acquired !== 1) {
            throw new RuntimeException('Another geographic reference initialization is running.');
        }
        try {
            return $this->initializeDatabase();
        } finally {
            $db->selectOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    protected function initializeDatabase(): array
    {
        $db = $this->connection();
        $reference = $this->identifier($this->database());
        $source = $this->identifier((string) $db->getDatabaseName());
        if ($reference === $source) {
            throw new RuntimeException('The reference database must be separate from the landlord database.');
        }
        $db->statement("CREATE DATABASE IF NOT EXISTS {$reference} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (self::COLUMNS as $table => $columns) {
            $quoted = $this->identifier($table);
            $db->statement("CREATE TABLE IF NOT EXISTS {$reference}.{$quoted} LIKE {$source}.{$quoted}");
        }
        $db->statement("CREATE TABLE IF NOT EXISTS {$reference}.geographic_manifest (id INT PRIMARY KEY, version INT NOT NULL, updated_at TIMESTAMP NOT NULL)");
        if ($db->table($this->database().'.geographic_manifest')->where('id', 1)->exists()) {
            return $this->validate();
        }
        foreach (self::COLUMNS as $table => $columns) {
            if ($db->table($this->database().'.'.$table)->exists()) {
                throw new RuntimeException('Reference data exists without a completion marker. Validate it before continuing.');
            }
            $db->statement("ALTER TABLE {$reference}.".$this->identifier($table).' AUTO_INCREMENT = 1');
        }
        $landlord = (string) config('multitenancy.landlord_database_connection_name', 'mysql');
        $configuration = config("database.connections.{$landlord}");
        config(['database.connections.geographic_reference' => array_merge($configuration, ['database' => $this->database(), 'url' => null])]);
        DB::purge('geographic_reference');
        $original = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('geographic_reference');
            DB::connection('geographic_reference')->transaction(function (): void {
                foreach ([CountrySeeder::class, StateSeeder::class, CitySeeder::class] as $class) {
                    $seeder = new $class;
                    $seeder->setContainer(app());
                    $seeder->run();
                }
                $reference = $this->identifier($this->database());
                $corrected = DB::affectingStatement("UPDATE {$reference}.cities city JOIN {$reference}.states state ON state.id = city.state_id SET city.country_id = state.country_id WHERE city.country_id <> state.country_id");
                Log::info('Geographic city parent normalization.', ['corrected' => $corrected]);
                $this->importAreaTowns();
                $this->validate(DB::connection('geographic_reference'));
                DB::table('geographic_manifest')->insert(['id' => 1, 'version' => 1, 'updated_at' => now()]);
            });
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('geographic_reference');
        }

        return $this->validate();
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
        $reference = $this->identifier($this->database());
        $counts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $counts[$table] = $db->table($this->database().'.'.$table)->count();
            if ($counts[$table] === 0) {
                throw new RuntimeException('Geographic reference '.$table.' is empty. Run geography:init first.');
            }
        }
        foreach ([['states', 'country_id', 'countries'], ['cities', 'state_id', 'states'], ['cities', 'country_id', 'countries'], ['city_pin_codes', 'city_id', 'cities'], ['city_pin_codes', 'state_id', 'states'], ['city_pin_codes', 'country_id', 'countries']] as [$table, $column, $parent]) {
            $missing = $db->selectOne("SELECT COUNT(*) AS total FROM {$reference}.{$table} child LEFT JOIN {$reference}.{$parent} parent ON parent.id = child.{$column} WHERE child.{$column} IS NULL OR parent.id IS NULL")->total;
            if ($missing > 0) {
                throw new RuntimeException("Geographic reference {$table} contains {$missing} missing {$parent} references.");
            }
        }
        $invalid = $db->selectOne("SELECT COUNT(*) AS total FROM {$reference}.city_pin_codes WHERE pin_code < 100000 OR pin_code > 999999 OR area_town IS NULL OR TRIM(area_town) = ''")->total;
        if ($invalid > 0) {
            throw new RuntimeException('Geographic reference contains invalid PIN codes or blank area/town names.');
        }
        $inconsistent = $db->selectOne("SELECT COUNT(*) AS total FROM {$reference}.cities city JOIN {$reference}.states state ON state.id = city.state_id WHERE city.country_id <> state.country_id")->total;
        $inconsistent += $db->selectOne("SELECT COUNT(*) AS total FROM {$reference}.city_pin_codes area JOIN {$reference}.cities city ON city.id = area.city_id WHERE area.state_id <> city.state_id OR area.country_id <> city.country_id")->total;
        if ($inconsistent > 0) {
            throw new RuntimeException('Geographic reference contains inconsistent country, state or city relationships.');
        }

        return $counts;
    }

    public function copyToDatabase(string $destination): array
    {
        $db = $this->connection();
        $target = $this->identifier($destination);
        $source = $this->identifier($this->database());
        if ($target === $source) {
            throw new RuntimeException('Cannot copy geographic reference data into itself.');
        }
        if (! $db->table($this->database().'.geographic_manifest')->where('id', 1)->where('version', 1)->exists()) {
            throw new RuntimeException('Geographic reference data is not ready. Run php artisan geography:init first.');
        }
        foreach (self::COLUMNS as $table => $columns) {
            if ($db->table($destination.'.'.$table)->exists()) {
                throw new RuntimeException('Bulk geographic copying only supports empty destination tables. Existing company data is preserved.');
            }
        }
        $start = microtime(true);
        $counts = $db->transaction(function () use ($db, $target, $source): array {
            $counts = [];
            foreach (self::COLUMNS as $table => $columns) {
                $quoted = $this->identifier($table);
                $list = implode(', ', array_map($this->identifier(...), $columns));
                $counts[$table] = $db->affectingStatement("INSERT INTO {$target}.{$quoted} ({$list}, created_at, updated_at) SELECT {$list}, NOW(), NOW() FROM {$source}.{$quoted}");
            }

            return $counts;
        });
        Log::info('Geographic masters bulk copied.', ['database' => $destination, 'rows' => $counts, 'seconds' => round(microtime(true) - $start, 3)]);

        return $counts;
    }
}
