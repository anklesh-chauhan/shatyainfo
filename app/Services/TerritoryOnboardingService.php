<?php

namespace App\Services;

use App\Models\CityPinCode;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Services\DataImport\ImportRowException;
use App\Services\DataImport\SpreadsheetFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TerritoryOnboardingService
{
    public const COLUMNS = ['code', 'name', 'parent_code', 'status', 'description', 'division_codes', 'cities_and_areas', 'division_names'];

    public function readFile(string $path, bool $isWorkbook = false): array
    {
        $this->authorize();
        if (! $isWorkbook) {
            return $this->readCsv($path);
        }
        $csv = null;
        try {
            $csv = app(SpreadsheetFile::class)->toCsv($path, 'Territories');

            return $this->readCsv($csv);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['territoryFile' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['territoryFile' => 'Cannot read the workbook. Upload a valid .xlsx file using the territory template.']);
        } finally {
            if ($csv !== null) {
                unlink($csv);
            }
        }
    }

    public function readCsv(string $path): array
    {
        $this->authorize();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['territoryFile' => 'Cannot read the territory CSV.']);
        }
        try {
            $headers = array_map(fn ($value) => strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))), fgetcsv($handle, escape: '') ?: []);
            if (count($headers) !== count(array_unique($headers)) || array_diff(array_merge(['code', 'name', 'parent_code', 'cities_and_areas'], Organization::divisionsEnabled() ? ['division_codes'] : []), $headers) || array_diff($headers, self::COLUMNS)) {
                throw ValidationException::withMessages(['territoryFile' => 'Use the territory template headers: '.implode(', ', self::COLUMNS).'.']);
            }
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
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
            'rows.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'rows.*.division_codes' => [Organization::divisionsEnabled() ? 'required' : 'nullable', 'string', 'max:5000'],
            'rows.*.division_names' => ['nullable', 'string', 'max:10000'],
            'rows.*.cities_and_areas' => ['required', 'string', 'max:10000'],
            'rows.*.parent_code' => ['nullable', 'string', 'max:255'],
            'rows.*.status' => ['required', Rule::in(['active', 'inactive'])],
            'rows.*.description' => ['nullable', 'string', 'max:5000'],
        ])->validate()['rows'];
        $divisionPlans = $this->divisionPlans($rows);
        $existing = Territory::withTrashed()->with(['divisions', 'cityPinCodes'])->get();
        $byId = $existing->keyBy('id');
        $existing = $existing->keyBy(fn (Territory $territory): string => strtoupper((string) $territory->code));
        $pending = collect($rows)->keyBy('code');
        $errors = [];
        foreach ($rows as $index => &$row) {
            $row['division_plans'] = array_values(array_intersect_key($divisionPlans, array_flip(array_map(fn ($code) => strtoupper(trim($code)), explode(';', $row['division_codes'])))));
            $parent = $row['parent_code'];
            $record = $existing->get($row['code']);
            if ($parent !== '' && ! $pending->has($parent) && (! $existing->has($parent) || $existing[$parent]->trashed())) {
                $errors['rows.'.$index.'.parent_code'] = 'Row '.($index + 2).': parent '.$parent.' was not found.';
            }
            if ($record && ($record->trashed() || $record->name !== $row['name'] || strtoupper((string) $byId->get($record->parent_territory_id)?->code) !== $parent || $record->status !== $row['status'] || (string) $record->description !== $row['description'])) {
                $errors['rows.'.$index.'.code'] = 'Row '.($index + 2).': '.$row['code'].' conflicts with an existing territory. Edit it in Territories first or use a different code.';
            }
            foreach (['division_codes', 'cities_and_areas'] as $field) {
                if ($field === 'division_codes' && $row[$field] === '' && ! Organization::divisionsEnabled()) {
                    $row[$field.'_ids'] = $record?->divisions->modelKeys() ?? [];

                    continue;
                }
                $items = array_map('trim', explode(';', $row[$field]));
                if (in_array('', $items, true)) {
                    $errors['rows.'.$index.'.'.$field] = 'Row '.($index + 2).': '.$field.' contains an empty entry.';

                    continue;
                }
                $resolved = [];
                foreach (array_unique($items) as $item) {
                    if ($field === 'division_codes') {
                        $resolved[] = $divisionPlans[strtoupper($item)]['id'];

                        continue;
                    } else {
                        $parts = array_map('trim', explode('|', $item));
                        if (count($parts) !== 3 || in_array('', $parts, true) || ! ctype_digit($parts[2] ?? '')) {
                            $errors['rows.'.$index.'.'.$field] = 'Row '.($index + 2).': use City|Area|PIN for each location, separated by semicolons.';

                            continue;
                        }
                        $matches = CityPinCode::query()->where('area_town', $parts[1])->where('pin_code', $parts[2])
                            ->whereHas('city', fn ($query) => $query->where('name', $parts[0]))->limit(2)->get();
                    }
                    if ($matches->count() !== 1) {
                        $errors['rows.'.$index.'.'.$field] = 'Row '.($index + 2).': '.$item.' must match exactly one existing '.($field === 'division_codes' ? 'division code' : 'city and area').'.';

                        continue;
                    }
                    $resolved[] = $matches->first()->id;
                }
                $resolved = array_values(array_unique($resolved));
                sort($resolved);
                $relationship = $field === 'division_codes' ? 'divisions' : 'cityPinCodes';
                $row[$field.'_ids'] = $resolved;
                if ($record) {
                    $current = $record->{$relationship}->modelKeys();
                    sort($current);
                    if ($current !== $resolved) {
                        $errors['rows.'.$index.'.'.$field] = 'Row '.($index + 2).': '.$row['code'].' has conflicting '.$field.'. Edit it in Territories first.';
                    }
                }
            }
            if (Territory::withTrashed()->where('name', $row['name'])->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                $errors['rows.'.$index.'.name'] = 'Row '.($index + 2).': this territory name is already in use.';
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
            $divisionIds = [];
            foreach ($rows as $row) {
                foreach ($row['division_plans'] as $plan) {
                    if ($plan['id'] > 0 || isset($divisionIds[$plan['id']])) {
                        continue;
                    }
                    $type = TypeMaster::firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
                    $divisionIds[$plan['id']] = OrganizationalUnit::create(['code' => $plan['code'], 'name' => $plan['name'], 'type_master_id' => $type->id, 'is_active' => true])->id;
                }
            }
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
                        $territory->divisions()->sync(array_map(fn ($id) => $divisionIds[$id] ?? $id, $row['division_codes_ids']));
                        $territory->cityPinCodes()->sync($row['cities_and_areas_ids']);
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

    private function divisionPlans(array $rows): array
    {
        $plans = [];
        $names = [];
        foreach ($rows as $index => $row) {
            $codes = $row['division_codes'] === '' ? [] : array_map(fn ($code) => strtoupper(trim($code)), explode(';', $row['division_codes']));
            $labels = $row['division_names'] === '' ? [] : array_map('trim', explode(';', $row['division_names']));
            if (($labels !== [] && count($codes) !== count($labels)) || in_array('', $codes, true) || count($codes) !== count(array_unique($codes))) {
                throw ValidationException::withMessages(['rows.'.$index.'.division_names' => 'Division codes and names must be matching semicolon-separated lists without duplicate or empty codes.']);
            }
            foreach ($codes as $offset => $code) {
                $name = $labels[$offset] ?? '';
                $record = OrganizationalUnit::withTrashed()->with('typeMaster')->where('code', $code)->first();
                if ($record && ($record->trashed() || $record->typeMaster?->name !== 'Division' || $record->typeMaster?->typeable_type !== OrganizationalUnit::class)) {
                    throw ValidationException::withMessages(['rows.'.$index.'.division_codes' => $code.' is deleted or belongs to another organizational-unit type.']);
                }
                $name = $name !== '' ? $name : ($record?->name ?? '');
                if ($name === '' || mb_strlen($name) > 255 || mb_strlen($code) > 255) {
                    throw ValidationException::withMessages(['rows.'.$index.'.division_names' => 'Supply a division name for new code '.$code.'. Codes and names may contain up to 255 characters.']);
                }
                if (($record && $record->name !== $name) || (isset($plans[$code]) && $plans[$code]['name'] !== $name)) {
                    throw ValidationException::withMessages(['rows.'.$index.'.division_names' => 'Conflicting division names for '.$code.'.']);
                }
                $nameKey = mb_strtolower($name);
                if ((isset($names[$nameKey]) && $names[$nameKey] !== $code) || OrganizationalUnit::withTrashed()->where('name', $name)->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                    throw ValidationException::withMessages(['rows.'.$index.'.division_names' => 'Division name '.$name.' is already used by another code.']);
                }
                $names[$nameKey] = $code;
                $plans[$code] ??= ['code' => $code, 'name' => $name, 'id' => $record?->id ?? -(count($plans) + 1), 'action' => $record ? 'Keep existing' : 'Create'];
            }
        }

        return $plans;
    }

    private function authorize(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()?->accountIsConfigured() ?? true, 409);
        abort_unless($service->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
    }
}
