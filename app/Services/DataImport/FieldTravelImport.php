<?php

namespace App\Services\DataImport;

use App\Models\AccountMaster;
use App\Models\AccountMasterPatch;
use App\Models\BaseModel;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Patch;
use App\Models\SalesDcr;
use App\Models\StandardFareChart;
use App\Models\Territory;
use App\Models\TransportMode;
use App\Models\TravelSegment;
use App\Models\TypeMaster;
use App\Services\Travel\TravelSegmentValidation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

class FieldTravelImport
{
    private static bool $running = false;

    private array $locations = [];

    public static function isRunning(): bool
    {
        return self::$running;
    }

    public function read(string $path, bool $historical = false): array
    {
        $allowed = $historical ? ['Travel Segments'] : ['Patches', 'Patch Customers', 'Standard Fares'];
        $reader = new Reader;
        $rows = [];
        $count = 0;
        try {
            $reader->open($path);
            $sheetNames = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
            }
            $recognized = array_intersect($sheetNames, array_keys(FieldTravelTemplate::COLUMNS));
            if ($recognized !== [] && array_intersect($sheetNames, $allowed) === []) {
                $allowed = array_values($recognized);
            }
            foreach ($reader->getSheetIterator() as $sheet) {
                if (! in_array($sheet->getName(), $allowed, true)) {
                    continue;
                }
                $headers = null;
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value), $row->toArray());
                    if ($headers === null) {
                        $headers = $values;
                        if ($headers !== FieldTravelTemplate::COLUMNS[$sheet->getName()]) {
                            throw ValidationException::withMessages(['file' => 'Keep the original column headers on '.$sheet->getName().'.']);
                        }

                        continue;
                    }
                    if (count(array_filter($values, fn ($value) => $value !== '')) === 0) {
                        continue;
                    }
                    if (++$count > 5000) {
                        throw ValidationException::withMessages(['file' => 'Use at most 5,000 rows per workbook.']);
                    }
                    $rows[$sheet->getName()][] = ['row' => $number, 'data' => array_combine($headers, array_slice(array_pad($values, count($headers), ''), 0, count($headers)))];
                }
            }
        } finally {
            $reader->close();
        }
        if ($count === 0) {
            throw ValidationException::withMessages(['file' => 'No data found. Fill data rows below the headers on '.implode(', ', $allowed).'. Workbook sheets found: '.implode(', ', $sheetNames).'. Instructions and Examples are never imported.']);
        }

        return $rows;
    }

    public function preview(array $sheets): array
    {
        $this->locations = [];
        $plans = [];
        $patches = [];
        $seen = [];
        $errors = [];
        foreach (FieldTravelTemplate::COLUMNS as $sheet => $headers) {
            foreach ($sheets[$sheet] ?? [] as $entry) {
                try {
                    $row = array_map(fn ($value) => trim((string) $value), $entry['data']);
                    $plan = $this->resolve($sheet, $row, $patches);
                    $key = $sheet.':'.$plan['key'];
                    if (isset($seen[$key])) {
                        $this->invalid('Duplicate row key: '.$plan['key']);
                    }
                    $seen[$key] = true;
                    if ($sheet === 'Patch Customers') {
                        $sequenceKey = 'sequence:'.strtoupper($row['patch_code']).':'.$row['sequence_no'];
                        if (isset($seen[$sequenceKey])) {
                            $this->invalid('Two customers have the same sequence number.');
                        }
                        $seen[$sequenceKey] = true;
                    }
                    if ($sheet === 'Patches') {
                        $nameKey = 'patch-name:'.strtolower($row['patch_name']);
                        if (isset($seen[$nameKey])) {
                            $this->invalid('Two patch codes have the same name.');
                        }
                        $seen[$nameKey] = true;
                    }
                    $record = $plan['record'];
                    $existing = $record->exists;
                    if ($existing && $record instanceof BaseModel && ! $record::query()->applyVisibility(class_basename($record))->whereKey($record->getKey())->exists()) {
                        $this->invalid('This record is outside your permitted access.');
                    }
                    if ($record instanceof AccountMasterPatch) {
                        $patch = $patches[strtoupper($row['patch_code'])] ?? Patch::findOrFail($plan['attributes']['patch_id']);
                        Gate::authorize($patch->exists ? 'update' : 'create', $patch->exists ? $patch : Patch::class);
                        if ($patch->approval_status === 'approved') {
                            $this->invalid('Customer assignments on approved patches cannot be changed by import.');
                        }
                    } else {
                        Gate::authorize($existing ? 'update' : 'create', $existing ? $record : $record::class);
                    }
                    $original = $record->getRawOriginal();
                    $record->fill($plan['attributes']);
                    $action = ! $existing ? 'Create' : ($record->isDirty() ? 'Update' : 'Unchanged');
                    if ($action === 'Update' && $record->getOriginal('approval_status') === 'approved') {
                        $this->invalid('Approved records cannot be updated by import.');
                    }
                    $plans[] = [
                        'sheet' => $sheet, 'row' => $entry['row'], 'key' => $plan['key'],
                        'action' => $action, 'class' => $record::class, 'id' => $existing ? $record->getKey() : null,
                        'attributes' => $plan['attributes'], 'original' => $original, 'values' => $row,
                    ];
                    if ($sheet === 'Patches') {
                        if (! $existing) {
                            $record->id = -count($plans);
                        }
                        $patches[strtoupper($record->code)] = $record;
                    }
                } catch (ValidationException $exception) {
                    $errors[] = $sheet.' row '.$entry['row'].': '.implode(' ', $exception->validator->errors()->all());
                } catch (AuthorizationException) {
                    $errors[] = $sheet.' row '.$entry['row'].': You do not have permission for this record.';
                }
            }
        }

        $locations = array_map(fn (CityPinCode $location): array => $location->getAttributes(), $this->locations);

        return ['plans' => $plans, 'locations' => $locations, 'errors' => $errors, 'fingerprint' => hash('sha256', serialize([$plans, $locations]))];
    }

    public function import(array $sheets, string $fingerprint, ?callable $progress = null): array
    {
        return DB::transaction(function () use ($sheets, $fingerprint, $progress): array {
            Organization::query()->lockForUpdate()->first();
            $preview = $this->preview($sheets);
            if ($preview['errors'] !== []) {
                $this->invalid(implode("\n", $preview['errors']));
            }
            if (! hash_equals($fingerprint, $preview['fingerprint'])) {
                $this->invalid('Records changed since preview. Preview the workbook again.');
            }
            $ids = [];
            $counts = ['Create' => 0, 'Update' => 0, 'Unchanged' => 0];
            $previous = self::$running;
            self::$running = true;
            try {
                $locationIds = [];
                foreach ($preview['locations'] as $temporaryId => $location) {
                    unset($location['id']);
                    $area = CityPinCode::firstOrCreate(['city_id' => $location['city_id'], 'pin_code' => $location['pin_code']], $location);
                    $locationIds[$temporaryId] = $area->id;
                }
                foreach ($preview['plans'] as $index => $plan) {
                    $attributes = $plan['attributes'];
                    foreach (['city_pin_code_id', 'from_area_town_id', 'to_area_town_id'] as $field) {
                        if (($attributes[$field] ?? 0) < 0) {
                            $attributes[$field] = $locationIds[$attributes[$field]];
                        }
                    }
                    if (($attributes['patch_id'] ?? 0) < 0) {
                        $attributes['patch_id'] = $ids[$attributes['patch_id']];
                    }
                    if ($plan['action'] !== 'Unchanged') {
                        $class = $plan['class'];
                        $record = $plan['id'] ? $class::findOrFail($plan['id']) : new $class;
                        $record->fill($attributes);
                        if ($record instanceof AccountMasterPatch) {
                            $record->forceFill(['updated_by' => auth()->id()] + ($record->exists ? [] : ['created_by' => auth()->id()]));
                        }
                        $record->save();
                        if ($record instanceof Patch && $record->city_pin_code_id) {
                            $record->territory->cityPinCodes()->syncWithoutDetaching([$record->city_pin_code_id]);
                        }
                        if ($plan['sheet'] === 'Patches' && ! $plan['id']) {
                            $ids[-($index + 1)] = $record->id;
                        }
                    }
                    $counts[$plan['action']]++;
                    if ($progress && (($index + 1) % 10 === 0 || $index + 1 === count($preview['plans']))) {
                        $progress($index + 1, count($preview['plans']));
                    }
                }
            } finally {
                self::$running = $previous;
            }

            return $counts;
        });
    }

    private function resolve(string $sheet, array $row, array $patches): array
    {
        $rules = [];
        foreach (FieldTravelTemplate::COLUMNS[$sheet] as $column) {
            $rules[$column] = ['nullable', 'string', 'max:255'];
        }
        foreach (FieldTravelTemplate::REQUIRED[$sheet] as $column) {
            $rules[$column] = ['required', 'string', 'max:255'];
        }
        foreach (['distance_km', 'fare_amount', 'distance_to_next_km'] as $column) {
            if (array_key_exists($column, $row)) {
                $rules[$column] = [in_array($column, FieldTravelTemplate::REQUIRED[$sheet], true) ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999999'];
            }
        }
        if ($sheet === 'Standard Fares') {
            $rules['distance_km'][] = 'gt:0';
            $rules['is_active'] = ['required', 'in:0,1'];
        }
        if ($sheet === 'Patch Customers') {
            $rules['sequence_no'] = ['required', 'integer', 'min:1', 'max:100000'];
        }
        if ($sheet === 'Travel Segments') {
            $rules['dcr_date'] = ['required', 'date_format:Y-m-d'];
            $rules['external_reference'] = ['required', 'string', 'max:100'];
        }
        Validator::make($row, $rules)->validate();

        $patch = null;
        if ($sheet !== 'Patches' && filled($row['patch_code'] ?? null)) {
            $patch = $patches[strtoupper($row['patch_code'])] ?? $this->lookup(Patch::class, 'code', $row['patch_code']);
        }
        if ($sheet === 'Patches') {
            $territory = $this->lookup(Territory::class, 'code', $row['territory_code']);
            $location = $this->location($row, '', false);
            if ($location?->exists && ! $territory->cityPinCodes()->where('city_id', $location->city_id)->where('pin_code', $location->pin_code)->exists()) {
                $this->invalid('The patch location must be linked to its territory.');
            }
            $record = Patch::withTrashed()->where('code', $row['patch_code'])->first() ?? new Patch;
            $this->notDeleted($record);
            if (Patch::withTrashed()->where('name', $row['patch_name'])->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                $this->invalid('Patch name is already used by another code.');
            }
            $attributes = ['code' => $row['patch_code'], 'name' => $row['patch_name'], 'territory_id' => $territory->id];
            if ($location) {
                $attributes['city_pin_code_id'] = $location->id;
            }
            foreach (['color' => 'color', 'remarks' => 'description'] as $column => $attribute) {
                if (filled($row[$column] ?? null)) {
                    $attributes[$attribute] = $row[$column];
                }
            }

            return compact('record', 'attributes') + ['key' => strtoupper($row['patch_code'])];
        }
        if ($sheet === 'Patch Customers') {
            $customer = $this->customer($row['customer_code']);
            if (! $customer->typeMaster || ($customer->typeMaster->name !== 'Customer' && $customer->typeMaster->parent?->name !== 'Customer')) {
                $this->invalid('Customer code must identify a customer account.');
            }
            $territory = Territory::findOrFail($patch->territory_id);
            $pins = $patch->city_pin_code_id < 0
                ? [$this->locations[$patch->city_pin_code_id]->pin_code]
                : ($patch->city_pin_code_id ? CityPinCode::whereKey($patch->city_pin_code_id)->pluck('pin_code') : $territory->cityPinCodes()->pluck('pin_code'));
            if (! $customer->addresses()->whereIn('pin_code', $pins)->exists()) {
                $this->invalid('Customer address is outside the patch location/territory.');
            }
            $record = AccountMasterPatch::where('patch_id', $patch->id)->where('account_master_id', $customer->id)->first() ?? new AccountMasterPatch;
            $this->notDeleted($record);
            if (AccountMasterPatch::where('patch_id', $patch->id)->where('sequence_no', $row['sequence_no'])->where('account_master_id', '!=', $customer->id)->exists()) {
                $this->invalid('Sequence number is already assigned to another customer.');
            }
            $attributes = ['patch_id' => $patch->id, 'account_master_id' => $customer->id, 'sequence_no' => (int) $row['sequence_no']];
            if (filled($row['distance_to_next_km'] ?? null)) {
                $attributes['distance_km'] = $row['distance_to_next_km'];
            }

            return compact('record', 'attributes') + ['key' => strtoupper($row['patch_code']).':'.$customer->id];
        }
        $from = $this->location($row, 'from_');
        $to = $this->location($row, 'to_');
        if ($sheet === 'Standard Fares') {
            $territory = filled($row['territory_code']) ? $this->lookup(Territory::class, 'code', $row['territory_code']) : null;
            if ($patch && (int) $patch->territory_id !== (int) $territory?->id) {
                $this->invalid('Patch and fare territory must match.');
            }
            [$fromId, $toId] = StandardFareChart::normalizeCityPair($from->id, $to->id);
            if ($fromId === $toId) {
                $this->invalid('Choose two different fare locations.');
            }
            $attributes = ['from_area_town_id' => $fromId, 'to_area_town_id' => $toId, 'territory_id' => $territory?->id, 'is_active' => (bool) $row['is_active']];
            $matches = StandardFareChart::withTrashed()->where($attributes)->limit(2)->get();
            if ($matches->count() > 1) {
                $this->invalid('Multiple existing fare routes match; resolve duplicates first.');
            }
            $record = $matches->first() ?? new StandardFareChart;
            $this->notDeleted($record);
            $attributes += ['distance_km' => $row['distance_km'], 'distance_source' => 'manual'];
            if (filled($row['fare_amount'])) {
                $attributes['fare_amount'] = $row['fare_amount'];
            }
            if ($patch) {
                if ($record->exists && $record->patch_id && (int) $record->patch_id !== (int) $patch->id) {
                    $this->invalid('This fare route is already associated with another patch.');
                }
                $attributes['patch_id'] = $patch->id;
            }
            if (filled($row['sfc_type'])) {
                $types = TypeMaster::where('typeable_type', StandardFareChart::class)->where('name', $row['sfc_type'])->limit(2)->get();
                if ($types->count() !== 1) {
                    $this->invalid('Fare type must match one existing SFC type.');
                }
                if ($record->exists && $record->type_master_id && $record->type_master_id !== $types->first()->id) {
                    $this->invalid('The route already exists with a different fare type.');
                }
                $attributes['type_master_id'] = $types->first()->id;
            }

            return compact('record', 'attributes') + ['key' => ($territory?->id ?? 'global').':'.$fromId.':'.$toId.':'.$row['is_active']];
        }
        $employee = $this->lookup(Employee::class, 'employee_id', $row['employee_code']);
        $dcrs = SalesDcr::query()->applyVisibility('SalesDcr')->where('user_id', $employee->login_id)->whereDate('dcr_date', $row['dcr_date'])->limit(2)->get();
        if (! $employee->login_id || $dcrs->count() !== 1) {
            $this->invalid('Employee and date must identify one existing DCR.');
        }
        $record = TravelSegment::withTrashed()->where('import_reference', $row['external_reference'])->first() ?? new TravelSegment;
        $this->notDeleted($record);
        if ($record->exists && (int) $record->sales_dcr_id !== (int) $dcrs->first()->id) {
            $this->invalid('External reference already belongs to another DCR.');
        }
        $attributes = [
            'import_reference' => $row['external_reference'], 'sales_dcr_id' => $dcrs->first()->id,
            'from_area_town_id' => $from->id, 'to_area_town_id' => $to->id,
            'distance_km' => $row['distance_km'], 'distance_source' => 'manual', 'is_auto_generated' => false,
        ];
        if ($patch) {
            $attributes['patch_id'] = $patch->id;
        }
        foreach (['from_customer_code' => 'from_account_id', 'to_customer_code' => 'to_account_id'] as $column => $attribute) {
            if (filled($row[$column] ?? null)) {
                $attributes[$attribute] = $this->customer($row[$column])->id;
            }
        }
        if (filled($row['transport_mode'])) {
            $attributes['transport_mode_id'] = $this->lookup(TransportMode::class, 'name', $row['transport_mode'])->id;
        }
        app(TravelSegmentValidation::class)->validate(array_merge($record->getAttributes(), $attributes));

        return compact('record', 'attributes') + ['key' => strtoupper($row['external_reference'])];
    }

    private function customer(string $value): AccountMaster
    {
        $matches = AccountMaster::query()->applyVisibility('AccountMaster')
            ->where(fn ($query) => $query->where('account_code', $value)->orWhere('external_reference', $value))
            ->limit(2)->get();
        if ($matches->count() !== 1) {
            $this->invalid('Customer reference '.$value.' must match exactly one accessible account by account code or external reference. Resolve missing or ambiguous references first.');
        }

        return $matches->first();
    }

    private function location(array $row, string $prefix, bool $required = true): ?CityPinCode
    {
        $values = [$row[$prefix.'city'] ?? '', $row[$prefix.'area'] ?? '', $row[$prefix.'pin_code'] ?? ''];
        if (! $required && count(array_filter($values)) === 0) {
            return null;
        }
        if (count(array_filter($values)) !== 3) {
            $this->invalid('Supply city, area and PIN together for '.$prefix.'location.');
        }
        Validator::make(['pin_code' => $values[2]], ['pin_code' => ['required', 'regex:/^[0-9]+$/', 'numeric', 'between:0,2147483647']])->validate();
        $city = $this->lookup(City::class, 'name', $values[0]);
        $location = CityPinCode::where('city_id', $city->id)->where('pin_code', $values[2])->orderBy('id')->first();
        if ($location) {
            return $location;
        }
        foreach ($this->locations as $location) {
            if ($location->city_id === $city->id && (int) $location->pin_code === (int) $values[2]) {
                return $location;
            }
        }
        $location = new CityPinCode(['city_id' => $city->id, 'state_id' => $city->state_id, 'country_id' => $city->country_id, 'pin_code' => (int) $values[2], 'area_town' => $values[1]]);
        $location->id = -(count($this->locations) + 1);
        $this->locations[$location->id] = $location;

        return $location;
    }

    private function lookup(string $class, string $column, string $value): Model
    {
        $query = $class::where($column, $value);
        if (in_array($class, [Territory::class, Patch::class, AccountMaster::class, Employee::class], true)) {
            $query->applyVisibility(class_basename($class));
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            $this->invalid(class_basename($class).' '.$value.' must match one accessible existing record.');
        }

        return $matches->first();
    }

    private function notDeleted(Model $record): void
    {
        if ($record->getAttribute('deleted_at')) {
            $this->invalid('A deleted record uses this code/reference. Restore it before importing.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
