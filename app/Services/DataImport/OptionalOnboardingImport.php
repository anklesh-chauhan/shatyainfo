<?php

namespace App\Services\DataImport;

use App\Enums\ItemType;
use App\Models\ApprovalSetting;
use App\Models\Category;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ItemBrand;
use App\Models\ItemMaster;
use App\Models\LeaveAdjustment;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveInstance;
use App\Models\LeaveLapseRecord;
use App\Models\LeaveType;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\PackingType;
use App\Models\PayrollLeaveSnapshot;
use App\Models\ShiftMaster;
use App\Models\State;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\UnitOfMeasurement;
use App\Services\CompanyOnboardingService;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OptionalOnboardingImport
{
    public const MODULES = [
        'products' => 'Products & Samples',
        'shifts' => 'Employee Shift Assignments',
        'holidays' => 'Holiday Calendar',
        'leave_openings' => 'Opening Leave Balances',
        'inventory_openings' => 'Opening Inventory',
    ];

    public const SHEETS = ['products' => 'Products', 'shifts' => 'Shifts', 'holidays' => 'Holidays', 'leave_openings' => 'Leave Openings', 'inventory_openings' => 'Inventory Openings'];

    public const COLUMNS = [
        'products' => ['sku', 'item_name', 'item_type', 'brand', 'category', 'unit', 'packing_type', 'per_packing_qty', 'hsn_code', 'purchase_price', 'selling_price', 'tax_names', 'description'],
        'shifts' => ['employee_code', 'shift_code', 'effective_from', 'effective_to'],
        'holidays' => ['date', 'name', 'country', 'state', 'location_code', 'is_optional', 'is_active'],
        'leave_openings' => ['employee_code', 'leave_type_code', 'opening_balance', 'effective_date', 'period_end'],
        'inventory_openings' => ['sku', 'location_code', 'quantity', 'unit_cost', 'effective_date'],
    ];

    public function authorize(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        abort_unless(Tenant::current()?->accountIsConfigured() ?? true, 409);
        abort_unless(app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
    }

    public function validateModule(string $module): void
    {
        abort_unless(isset(self::MODULES[$module]), 422, 'Choose a supported import.');
    }

    public function read(string $module, string $path, bool $workbook): array
    {
        $this->authorize();
        $this->validateModule($module);
        $temporary = null;
        $stream = null;
        try {
            if ($workbook) {
                $temporary = app(SpreadsheetFile::class)->toCsv($path, self::SHEETS[$module]);
                $path = $temporary;
            }
            $stream = fopen($path, 'r');
            if ($stream === false) {
                throw new ImportRowException('Cannot read this file.');
            }
            $headers = array_map(fn ($value): string => trim(ltrim((string) $value, "\xEF\xBB\xBF")), fgetcsv($stream, escape: '') ?: []);
            if ($headers !== self::COLUMNS[$module]) {
                throw new ImportRowException('Use the '.self::SHEETS[$module].' template headers in their original order.');
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $line++;
                if (collect($values)->every(fn ($value): bool => trim((string) $value) === '')) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new ImportRowException("Row {$line} has an incorrect number of columns.");
                }
                $rows[] = ['line' => $line, 'data' => array_combine($headers, $values)];
                if (count($rows) > 500) {
                    throw new ImportRowException('Import up to 500 rows at a time.');
                }
            }

            return $rows;
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'Unable to read this file. Upload a valid CSV or XLSX template.']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($temporary !== null) {
                unlink($temporary);
            }
        }
    }

    /** Preview resolves records and validates data without saving or firing model events. */
    public function preview(string $module, array $rows): array
    {
        $this->authorize();
        $this->validateModule($module);
        Validator::make(['rows' => $rows], ['rows' => ['required', 'array', 'min:1', 'max:500']])->validate();
        $plans = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $input) {
            $line = $input['line'] ?? $index + 2;
            try {
                $data = array_map(fn ($value): string => trim((string) $value), $input['data']);
                if (array_diff(array_keys($data), self::COLUMNS[$module])) {
                    throw new ImportRowException('Unknown columns. Use the downloaded template.');
                }
                $data = array_replace(array_fill_keys(self::COLUMNS[$module], ''), $data);
                $plan = match ($module) {
                    'products' => $this->product($data),
                    'shifts' => $this->shift($data),
                    'holidays' => $this->holiday($data),
                    'leave_openings' => $this->leaveOpening($data),
                    'inventory_openings' => $this->inventoryOpening($data),
                };
                $key = mb_strtolower($plan['key']);
                if (isset($seen[$key])) {
                    throw new ImportRowException('Duplicate identity in this file; use one row per record.');
                }
                if ($module === 'shifts') {
                    foreach ($plans as $previous) {
                        $a = $plan['attributes'];
                        $b = $previous['attributes'];
                        if ($a['employee_id'] === $b['employee_id']
                            && $a['effective_from'] <= ($b['effective_to'] ?? '9999-12-31')
                            && $b['effective_from'] <= ($a['effective_to'] ?? '9999-12-31')) {
                            throw new ImportRowException('Shift dates overlap with row '.$previous['line'].'.');
                        }
                    }
                }
                $seen[$key] = true;
                $plans[] = ['line' => $line, ...$plan];
            } catch (ImportRowException|ValidationException $exception) {
                $errors[] = [
                    'row' => $line,
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->implode(' ')
                        : $exception->getMessage(),
                ];
            }
        }

        return [
            'plans' => $plans,
            'errors' => $errors,
            'fingerprint' => hash('sha256', json_encode($plans, JSON_THROW_ON_ERROR)),
        ];
    }

    public function import(string $module, array $rows, string $fingerprint): array
    {
        $this->authorize();

        return (new Organization)->getConnection()->transaction(function () use ($module, $rows, $fingerprint): array {
            Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $preview = $this->preview($module, $rows);
            if ($preview['errors'] !== []) {
                throw ValidationException::withMessages(['file' => collect($preview['errors'])->map(fn ($error): string => 'Row '.$error['row'].': '.$error['message'])->implode(' ')]);
            }
            if (! hash_equals($fingerprint, $preview['fingerprint'])) {
                throw ValidationException::withMessages(['file' => 'Records changed since preview. Preview the file again before importing.']);
            }
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($preview['plans'] as $plan) {
                if ($plan['action'] === 'unchanged') {
                    $result['unchanged']++;

                    continue;
                }
                $class = $plan['model'];
                $record = $plan['id'] ? $class::query()->findOrFail($plan['id']) : new $class;
                try {
                    if ($module === 'inventory_openings') {
                        $stock = app(InventoryService::class)->stockFor($plan['attributes']['item_master_id'], $plan['attributes']['location_master_id']);
                        $this->assertEmptyInventory($stock->item_master_id, $stock->location_master_id);
                    }
                    $record->forceFill($plan['attributes'])->save();
                    if ($record instanceof InventoryAdjustment) {
                        app(InventoryService::class)->postAdjustment($record, auth()->id(), Carbon::parse($plan['effective_date'])->startOfDay());
                    }
                    if ($record instanceof ItemMaster && $plan['taxes'] !== null) {
                        $record->taxes()->sync($plan['taxes']);
                    }
                } catch (ImportRowException|ValidationException $exception) {
                    throw ValidationException::withMessages(['file' => 'Row '.$plan['line'].': '.($exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage())]);
                }
                $result[$plan['action']]++;
            }

            return $result;
        });
    }

    private function leaveOpening(array $data): array
    {
        Validator::make($data, [
            'employee_code' => ['required', 'string'],
            'leave_type_code' => ['required', 'string'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_date'],
        ])->validate();
        $employee = $this->reference(Employee::query()->where('employee_id', $data['employee_code'])->where('is_active', true), 'employee_code');
        $type = $this->reference(LeaveType::query()->where('code', $data['leave_type_code'])->where('is_active', true), 'leave_type_code');
        $record = $this->existing(LeaveBalance::withTrashed()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)) ?? new LeaveBalance;
        $attributes = [
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'opening_balance' => number_format((float) $data['opening_balance'], 2, '.', ''),
            'year_start_date' => $data['effective_date'], 'year_end_date' => $data['period_end'],
        ];
        $plan = $this->plan($record, $attributes, $data['employee_code'].' / '.$data['leave_type_code']);
        if ($plan['action'] === 'updated') {
            throw new ImportRowException('A different opening balance already exists. Use the normal HR adjustment workflow to correct it.');
        }
        if (! $record->exists) {
            foreach ([LeaveInstance::class, LeaveAdjustment::class, LeaveEncashment::class, LeaveLapseRecord::class, PayrollLeaveSnapshot::class] as $class) {
                if ($class::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->exists()) {
                    throw new ImportRowException('Leave activity already exists for this employee and leave type. Use the normal HR adjustment workflow.');
                }
            }
        }

        return $plan;
    }

    private function inventoryOpening(array $data): array
    {
        Validator::make($data, [
            'sku' => ['required', 'string'], 'location_code' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])->validate();
        $item = $this->reference(ItemMaster::query()->where('sku', $data['sku']), 'sku');
        if ((float) $data['quantity'] * (float) $data['unit_cost'] > 99999999999.9999) {
            throw new ImportRowException('The total opening inventory value exceeds the supported limit.');
        }
        $location = $this->reference(LocationMaster::query()->where('location_code', $data['location_code'])->where('is_active', true), 'location_code');
        $number = 'OPEN-'.$item->id.'-'.$location->id;
        $record = $this->existing(InventoryAdjustment::withTrashed()->where('adjustment_number', $number)) ?? new InventoryAdjustment;
        $attributes = [
            'adjustment_number' => $number, 'item_master_id' => $item->id, 'location_master_id' => $location->id,
            'adjustment_type' => 'receipt', 'quantity' => number_format((float) $data['quantity'], 3, '.', ''),
            'unit_cost' => number_format((float) $data['unit_cost'], 4, '.', ''),
            'reason' => 'Opening inventory '.$data['effective_date'],
        ];
        $plan = $this->plan($record, $attributes, $data['sku'].' / '.$data['location_code']);
        if ($record->exists && ($plan['action'] !== 'unchanged' || ! $record->isPosted())) {
            throw new ImportRowException('A different or unposted opening adjustment already exists. Review it in Inventory Adjustments.');
        }
        if (! $record->exists) {
            $this->assertEmptyInventory($item->id, $location->id);
            if (ApprovalSetting::moduleRequiresApproval(InventoryAdjustment::class)) {
                throw new ImportRowException('Inventory adjustments require approval. Create and approve the opening adjustment through Inventory Adjustments.');
            }
        }

        return [...$plan, 'effective_date' => $data['effective_date']];
    }

    private function assertEmptyInventory(int $itemId, int $locationId): void
    {
        $stock = InventoryStock::query()->where('item_master_id', $itemId)->where('location_master_id', $locationId)->first();
        if (($stock && ((float) $stock->quantity_on_hand != 0 || (float) $stock->quantity_reserved != 0))
            || InventoryMovement::query()->where('item_master_id', $itemId)->where('location_master_id', $locationId)->exists()
            || ItemMaster::query()->whereKey($itemId)->where('opening_stock', '!=', 0)->exists()
            || (new ItemMaster)->getConnection()->table('item_location')->where('item_master_id', $itemId)->where('location_master_id', $locationId)->where('quantity', '!=', 0)->exists()) {
            throw new ImportRowException('Stock or movement history already exists. Use an inventory adjustment instead of importing an opening balance.');
        }
    }

    private function product(array $data): array
    {
        $values = array_filter($data, fn (string $value): bool => $value !== '');
        Validator::make($values, [
            'sku' => ['required', 'string', 'max:255'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_type' => ['sometimes', Rule::enum(ItemType::class)],
            'hsn_code' => ['sometimes', 'string', 'max:255'],
            'per_packing_qty' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'selling_price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'description' => ['sometimes', 'string', 'max:10000'],
        ])->validate();
        $record = $this->existing(ItemMaster::withTrashed()->where('sku', $data['sku'])) ?? new ItemMaster;
        if ($record->parent_id || $record->has_variants) {
            throw new ImportRowException('Manage variant products in Items. This template imports standalone items.');
        }
        $attributes = array_intersect_key($values, array_flip(['sku', 'item_name', 'item_type', 'hsn_code', 'per_packing_qty', 'purchase_price', 'selling_price', 'description']));
        foreach (['purchase_price', 'selling_price'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = number_format((float) $attributes[$field], 2, '.', '');
            }
        }
        foreach ([
            'brand' => [ItemBrand::class, 'name', 'item_brand_id'],
            'unit' => [UnitOfMeasurement::class, 'abbreviation', 'unit_of_measurement_id'],
            'packing_type' => [PackingType::class, 'name', 'packaging_type_id'],
        ] as $field => [$class, $lookup, $attribute]) {
            if ($data[$field] !== '') {
                $attributes[$attribute] = $this->reference($class::query()->where($lookup, $data[$field]), $field)->id;
            }
        }
        if ($data['category'] !== '') {
            $attributes['category_id'] = $this->reference(Category::query()->where('modelable_type', ItemMaster::class)->where('name', $data['category']), 'category')->id;
            $attributes['category_type'] = Category::class;
        }
        $taxes = null;
        if ($data['tax_names'] !== '') {
            $taxes = [];
            $rate = 0;
            foreach (array_unique(array_map('trim', explode(';', $data['tax_names']))) as $name) {
                $tax = $this->reference(Tax::query()->where('name', $name)->where('is_active', true), 'tax_names');
                $taxes[] = $tax->id;
                $rate += (float) $tax->total_rate;
            }
            if ($rate < 0 || $rate > 100) {
                throw new ImportRowException('Combined tax rate must be between 0 and 100.');
            }
            sort($taxes);
            $attributes['tax_rate'] = number_format($rate, 2, '.', '');
        }

        return $this->plan($record, $attributes, $data['sku'], $taxes);
    }

    private function shift(array $data): array
    {
        Validator::make($data, [
            'employee_code' => ['required', 'string'],
            'shift_code' => ['required', 'string'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ])->validate();
        $employee = $this->reference(Employee::query()->where('employee_id', $data['employee_code'])->where('is_active', true), 'employee_code');
        $shift = $this->reference(ShiftMaster::query()->where('code', $data['shift_code']), 'shift_code');
        $record = $this->existing(EmployeeShift::withTrashed()->where('employee_id', $employee->id)->whereDate('effective_from', $data['effective_from'])) ?? new EmployeeShift;
        $end = $data['effective_to'] !== '' ? $data['effective_to'] : ($record->effective_to ?: null);
        if ($end !== null && $end < $data['effective_from']) {
            throw new ImportRowException('The end date must be on or after the start date.');
        }
        $overlaps = EmployeeShift::query()->where('employee_id', $employee->id)
            ->when($record->exists, fn (Builder $query) => $query->whereKeyNot($record->id))
            ->whereDate('effective_from', '<=', $end ?? '9999-12-31')
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']))
            ->exists();
        if ($overlaps) {
            throw new ImportRowException('Shift dates overlap with an existing assignment. Close the old assignment in HR first.');
        }
        $attributes = [
            'employee_id' => $employee->id, 'shift_master_id' => $shift->id,
            'effective_from' => $data['effective_from'], 'effective_to' => $end,
            'is_current' => (int) ($data['effective_from'] <= today()->toDateString() && ($end === null || $end >= today()->toDateString())),
        ];

        return $this->plan($record, $attributes, $data['employee_code'].' / '.$data['effective_from']);
    }

    private function holiday(array $data): array
    {
        Validator::make($data, [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'is_optional' => ['nullable', Rule::in(['yes', 'no', '1', '0'])],
            'is_active' => ['nullable', Rule::in(['yes', 'no', '1', '0'])],
        ])->validate();
        $country = $data['country'] !== '' ? $this->reference(Country::query()->where('name', $data['country']), 'country') : null;
        if ($data['state'] !== '' && ! $country) {
            throw new ImportRowException('Provide country when specifying a state.');
        }
        $state = $data['state'] !== '' ? $this->reference(State::query()->where('name', $data['state'])->where('country_id', $country->id), 'state') : null;
        $location = $data['location_code'] !== '' ? $this->reference(LocationMaster::query()->where('location_code', $data['location_code'])->where('is_active', true), 'location_code') : null;
        $attributes = ['date' => $data['date'], 'country_id' => $country?->id, 'state_id' => $state?->id, 'location_master_id' => $location?->id];
        $record = $this->existing(Holiday::withTrashed()->where($attributes)) ?? new Holiday;
        $attributes['name'] = $data['name'];
        foreach (['is_optional' => false, 'is_active' => true] as $field => $default) {
            if ($data[$field] !== '' || ! $record->exists) {
                $attributes[$field] = $data[$field] === '' ? $default : in_array($data[$field], ['yes', '1'], true);
            }
        }

        return $this->plan($record, $attributes, implode(' / ', [$data['date'], $data['country'] ?: 'All countries', $data['state'] ?: 'All states', $data['location_code'] ?: 'All locations']));
    }

    private function reference(Builder $query, string $field): Model
    {
        $records = $query->limit(2)->get();
        if ($records->count() !== 1) {
            throw new ImportRowException("Unknown or ambiguous {$field}. Use an existing value from References.");
        }

        return $records->first();
    }

    private function existing(Builder $query): ?Model
    {
        $records = $query->limit(2)->get();
        if ($records->count() > 1) {
            throw new ImportRowException('Multiple existing records match this identity. Resolve duplicates first.');
        }
        $record = $records->first();
        if ($record?->trashed()) {
            throw new ImportRowException('This identity belongs to a deleted record. Restore or review it first.');
        }

        return $record;
    }

    private function plan(Model $record, array $attributes, string $key, ?array $taxes = null): array
    {
        $original = $record->getRawOriginal();
        $currentTaxes = $record instanceof ItemMaster && $record->exists ? $record->taxes()->pluck('taxes.id')->sort()->values()->all() : [];
        $record->forceFill($attributes);
        $changed = $record->isDirty() || ($taxes !== null && $taxes !== $currentTaxes);
        $approvalStatus = method_exists($record, 'getApprovalStatus') ? $record->getApprovalStatus() : $record->getRawOriginal('approval_status');
        if ($record->exists && $changed && in_array($approvalStatus, ['approved', 'pending'], true)) {
            throw new ImportRowException('Approved records or records awaiting approval must be changed through their normal approval workflow.');
        }

        return [
            'model' => $record::class, 'id' => $record->exists ? $record->id : null,
            'key' => $key, 'attributes' => $attributes, 'taxes' => $taxes,
            'original' => $original, 'original_taxes' => $currentTaxes,
            'action' => ! $record->exists ? 'created' : ($changed ? 'updated' : 'unchanged'),
        ];
    }
}
