<?php

namespace App\Services\DataImport;

use App\Models\Category;
use App\Models\Country;
use App\Models\Employee;
use App\Models\ItemBrand;
use App\Models\ItemMaster;
use App\Models\LeaveType;
use App\Models\LocationMaster;
use App\Models\PackingType;
use App\Models\ShiftMaster;
use App\Models\State;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class OptionalOnboardingTemplate
{
    public const HELP = [
        'leave_openings' => 'Create employees and leave types first. Use employee_code and leave_type_code from References. Enter an opening balance of 0 to 999.99 days, an effective_date on or before today, and period_end on or after that date (YYYY-MM-DD). This creates the opening record shown in the leave ledger. One opening per employee and leave type; existing identical rows are unchanged. Different openings or existing leave/payroll activity are rejected; use normal HR adjustments for corrections.',
        'inventory_openings' => 'Create items with SKUs and stock locations first. Enter quantity greater than zero (up to 3 decimal places), unit_cost (up to 4 decimal places), and effective_date on or before today (YYYY-MM-DD). Zero stock needs no import row. Confirmation posts a receipt adjustment and dated movement. One opening per SKU and location; identical posted openings are unchanged. Existing stock, movement history or conflicting openings are rejected. If inventory adjustments require approval, use Inventory Adjustments and its approval workflow.',
        'products' => 'Use a unique SKU per item. Item codes are generated automatically. Blank item_type means a general product on creation; sample, gift and promotional_input are supported. Existing optional values are preserved when blank. Use existing brands, item categories, unit abbreviations, packing types and tax names from References. Separate tax names with semicolons; supplied names replace tax assignments and set the combined tax rate. Opening stock and variants are managed separately.',
        'shifts' => 'Create employees and shift masters first. Match employees by HR employee code, never login ID. Dates use YYYY-MM-DD. Employee code + start date identifies an assignment. Blank end date preserves an existing end date; a new blank-ended assignment is open-ended. Overlapping periods are rejected, including overlaps inside the file. Close previous assignments in HR before adding new periods.',
        'holidays' => 'Dates use YYYY-MM-DD. Date + country + state + location identifies a holiday. Blank scope means all countries, states or locations; supply country with state. Choose existing names and location codes from References. Changing scope creates a different holiday. is_optional and is_active accept yes/no or 1/0. New rows default to mandatory and active; blank flags preserve existing values.',
    ];

    public function write(string $module, string $path): void
    {
        $importer = app(OptionalOnboardingImport::class);
        $importer->authorize();
        $importer->validateModule($module);
        $writer = new Writer;
        $writer->openToFile($path);
        $heading = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText();
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        try {
            $writer->getCurrentSheet()->setName(OptionalOnboardingImport::SHEETS[$module])->setColumnWidthForRange(24, 1, count(OptionalOnboardingImport::COLUMNS[$module]));
            $writer->addRow(Row::fromValues(OptionalOnboardingImport::COLUMNS[$module], $heading));
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions')->setColumnWidth(110, 2);
            foreach ([
                ['Workflow', 'Fill only '.OptionalOnboardingImport::SHEETS[$module].'. Upload XLSX or UTF-8 CSV, up to 500 rows and 2 MB. Preview, correct every error, then confirm. Preview never saves records. Confirm imports the whole file or none of it.'],
                ['Matching and reruns', 'Identical rows are unchanged. Opening balances are never overwritten; other modules update records by their stable identity. Do not change identifiers between uploads. Duplicate identities, deleted identities and ambiguous references are rejected.'],
                ['Module instructions', self::HELP[$module]],
                ['Examples', 'Examples are illustrative and are never imported. Replace them with your company values from References.'],
                ['Protected records', 'Approved records must be edited through their normal workflow. Unknown columns, system IDs, audit fields and approval decisions are not accepted.'],
                ['References', 'Reference values reflect this company at download time. Keep identifiers as Text, preserve leading zeros, and use values rather than formulas. Missing masters must be created before preview.'],
            ] as $row) {
                $writer->addRow(Row::fromValues($row, $text)->setHeight(70));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Examples')->setColumnWidthForRange(24, 1, count(OptionalOnboardingImport::COLUMNS[$module]));
            $writer->addRow(Row::fromValues(OptionalOnboardingImport::COLUMNS[$module], $heading));
            $example = match ($module) {
                'products' => ['SAMPLE-001', 'Example sample', 'sample', '', '', '', '', '10', '3004', '10.00', '15.00', '', 'Replace with your own item'],
                'shifts' => ['EMP001', 'DAY', '2026-01-01', '2026-12-31'],
                'holidays' => ['2026-12-25', 'Example holiday', '', '', '', 'no', 'yes'],
                'leave_openings' => ['EMP001', 'CL', '12.50', today()->startOfYear()->toDateString(), today()->endOfYear()->toDateString()],
                'inventory_openings' => ['SAMPLE-001', 'HQ', '100.000', '10.0000', today()->toDateString()],
            };
            $writer->addRow(Row::fromValues($example, $text));
            $writer->addNewSheetAndMakeItCurrent()->setName('References')->setColumnWidthForRange(38, 1, 3);
            $writer->addRow(Row::fromValues(['Field', 'Value', 'Description'], $heading));
            $sources = match ($module) {
                'leave_openings' => [
                    ['employee_code', Employee::query()->where('is_active', true), 'employee_id', 'first_name'],
                    ['leave_type_code', LeaveType::query()->where('is_active', true), 'code', 'name'],
                ],
                'inventory_openings' => [
                    ['sku', ItemMaster::query()->whereNotNull('sku'), 'sku', 'item_name'],
                    ['location_code', LocationMaster::query()->where('is_active', true), 'location_code', 'name'],
                ],
                'products' => [
                    ['sku (existing)', ItemMaster::query()->whereNotNull('sku'), 'sku', 'item_name'],
                    ['brand', ItemBrand::query(), 'name', 'name'],
                    ['category', Category::query()->where('modelable_type', ItemMaster::class), 'name', 'name'],
                    ['unit', UnitOfMeasurement::query(), 'abbreviation', 'name'],
                    ['packing_type', PackingType::query(), 'name', 'description'],
                    ['tax_names', Tax::query()->where('is_active', true), 'name', 'total_rate'],
                ],
                'shifts' => [
                    ['employee_code', Employee::query()->where('is_active', true), 'employee_id', 'first_name'],
                    ['shift_code', ShiftMaster::query(), 'code', 'name'],
                ],
                'holidays' => [
                    ['country', Country::query(), 'name', 'name'],
                    ['state', State::query()->with('country'), 'name', 'country.name'],
                    ['location_code', LocationMaster::query()->where('is_active', true), 'location_code', 'name'],
                ],
            };
            foreach ($sources as [$field, $query, $value, $description]) {
                foreach ($query->orderBy('id')->lazy() as $record) {
                    $writer->addRow(Row::fromValues([$field, (string) $record->$value, (string) data_get($record, $description)], $text));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
