<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\Territory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TerritoryOnboardingService
{
    public const COLUMNS = ['code', 'name', 'parent_code', 'status', 'description'];

    public function readCsv(string $path): array
    {
        $this->authorize();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['territoryFile' => 'Cannot read the territory CSV.']);
        }
        try {
            $headers = array_map(fn ($value) => strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))), fgetcsv($handle, escape: '') ?: []);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['code', 'name', 'parent_code'], $headers) || array_diff($headers, self::COLUMNS)) {
                throw ValidationException::withMessages(['territoryFile' => 'Use the territory template headers: '.implode(', ', self::COLUMNS).'.']);
            }
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['territoryFile' => 'CSV row '.(count($rows) + 2).' has an incorrect number of columns.']);
                }
                $rows[] = array_combine($headers, $values);
                if (count($rows) > 500) {
                    throw ValidationException::withMessages(['territoryFile' => 'Import up to 500 territories at a time.']);
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function preview(array $rows): array
    {
        $this->authorize();
        $rows = array_map(function (array $row): array {
            $row = array_intersect_key($row, array_flip(self::COLUMNS));
            foreach (self::COLUMNS as $key) {
                $row[$key] = trim((string) ($row[$key] ?? ''));
            }
            $row['code'] = strtoupper($row['code']);
            $row['parent_code'] = strtoupper($row['parent_code']);
            $row['status'] = strtolower($row['status']) ?: 'active';

            return $row;
        }, array_values($rows));
        $rows = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.code' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.parent_code' => ['nullable', 'string', 'max:255'],
            'rows.*.status' => ['required', Rule::in(['active', 'inactive'])],
            'rows.*.description' => ['nullable', 'string', 'max:5000'],
        ])->validate()['rows'];
        $existing = Territory::withTrashed()->get();
        $byId = $existing->keyBy('id');
        $existing = $existing->keyBy(fn (Territory $territory): string => strtoupper((string) $territory->code));
        $pending = collect($rows)->keyBy('code');
        $errors = [];
        foreach ($rows as $index => &$row) {
            $parent = $row['parent_code'];
            $record = $existing->get($row['code']);
            if ($parent !== '' && ! $pending->has($parent) && (! $existing->has($parent) || $existing[$parent]->trashed())) {
                $errors['rows.'.$index.'.parent_code'] = 'Row '.($index + 2).': parent '.$parent.' was not found.';
            }
            if ($record && ($record->trashed() || $record->name !== $row['name'] || strtoupper((string) $byId->get($record->parent_territory_id)?->code) !== $parent || $record->status !== $row['status'] || (string) $record->description !== $row['description'])) {
                $errors['rows.'.$index.'.code'] = 'Row '.($index + 2).': '.$row['code'].' conflicts with an existing territory. Edit it in Territories first or use a different code.';
            }
            $row['action'] = $record ? 'Keep existing' : 'Create';
        }
        unset($row);
        foreach ($rows as $index => $row) {
            $seen = [];
            $code = $row['code'];
            while ($code !== '') {
                if (isset($seen[$code])) {
                    $errors['rows.'.$index.'.parent_code'] = 'Row '.($index + 2).': circular territory hierarchy detected.';
                    break;
                }
                $seen[$code] = true;
                $code = $pending->has($code) ? $pending[$code]['parent_code'] : strtoupper((string) $byId->get($existing->get($code)?->parent_territory_id)?->code);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    public function import(array $rows): int
    {
        $this->authorize();

        return DB::transaction(function () use ($rows): int {
            $organization = app(CompanyOnboardingService::class)->organization();
            Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $rows = $this->preview($rows);
            $created = 0;
            $ids = Territory::query()->get()->mapWithKeys(fn (Territory $territory): array => [strtoupper((string) $territory->code) => $territory->id])->all();
            while ($rows !== []) {
                foreach ($rows as $index => $row) {
                    if ($row['parent_code'] !== '' && ! isset($ids[$row['parent_code']])) {
                        continue;
                    }
                    if (! isset($ids[$row['code']])) {
                        $territory = Territory::create([
                            'code' => $row['code'], 'name' => $row['name'],
                            'parent_territory_id' => $ids[$row['parent_code']] ?? null,
                            'status' => $row['status'], 'description' => $row['description'] ?: null,
                        ]);
                        $ids[$row['code']] = $territory->id;
                        $created++;
                    }
                    unset($rows[$index]);
                }
            }
            app(CompanyOnboardingService::class)->mark('territories_reviewed');

            return $created;
        });
    }

    private function authorize(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()?->accountIsConfigured() ?? true, 409);
        abort_unless($service->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
    }
}
