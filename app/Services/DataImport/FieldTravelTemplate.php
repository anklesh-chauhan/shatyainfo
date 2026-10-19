<?php

namespace App\Services\DataImport;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class FieldTravelTemplate
{
    public const COLUMNS = [
        'Patches' => ['patch_code', 'patch_name', 'territory_code', 'city', 'area', 'pin_code', 'color', 'remarks'],
        'Patch Customers' => ['patch_code', 'customer_code', 'sequence_no', 'distance_to_next_km'],
        'Standard Fares' => ['territory_code', 'patch_code', 'from_city', 'from_area', 'from_pin_code', 'to_city', 'to_area', 'to_pin_code', 'distance_km', 'fare_amount', 'sfc_type', 'is_active'],
        'Travel Segments' => ['external_reference', 'employee_code', 'dcr_date', 'patch_code', 'from_customer_code', 'to_customer_code', 'from_city', 'from_area', 'from_pin_code', 'to_city', 'to_area', 'to_pin_code', 'transport_mode', 'distance_km'],
    ];

    public const REQUIRED = [
        'Patches' => ['patch_code', 'patch_name', 'territory_code'],
        'Patch Customers' => ['patch_code', 'customer_code', 'sequence_no'],
        'Standard Fares' => ['from_city', 'from_area', 'from_pin_code', 'to_city', 'to_area', 'to_pin_code', 'distance_km', 'is_active'],
        'Travel Segments' => ['external_reference', 'employee_code', 'dcr_date', 'from_city', 'from_area', 'from_pin_code', 'to_city', 'to_area', 'to_pin_code', 'distance_km'],
    ];

    public function instructions(): array
    {
        return [
            ['Prepare', 'Import territories first. Customers and their addresses must exist before Patch Customers. Use the master workbook for Patches, Patch Customers and Standard Fares; use the separate historical workbook for Travel Segments. Blank sheets are skipped. Maximum 5,000 data rows, 10 MB.'],
            ['Preview and save', 'Keep sheet names and headers unchanged. Preview lists Create, Update, Unchanged and row errors. Nothing saves until Import reviewed workbook. All rows save together; any error rolls back the whole workbook. Changed records require a fresh preview.'],
            ['Patches', 'Required: patch_code, patch_name, territory_code. Code identifies an existing patch for updates. Names must be unique. Optional location requires city + area + pin_code together and must be linked to the territory. Blank optional cells preserve existing values.'],
            ['Patch Customers', 'Required: patch_code, customer_code, sequence_no (positive integer, unique within patch). Customer must have an address in the patch location/territory. distance_to_next_km is the distance FROM this customer TO the next sequence, not cumulative distance from HQ. Blank distance preserves existing value. Imports never remove assignments. Approved patches cannot have assignments changed.'],
            ['Standard Fares', 'Required: both city/area/PIN locations, distance_km greater than zero, is_active (1 or 0). Optional: territory_code (blank means global), patch_code, fare_amount (nonnegative), sfc_type. Existing fare types must match. A–B and B–A are one route per territory and active status; patch/type do not create separate routes. Conflicts are rejected.'],
            ['Locations', 'City, area and PIN must match exactly one existing location. Keep PINs as text. Ambiguous names are rejected; no new geographic references are created.'],
            ['Historical travel', 'Required: external_reference (stable unique identifier, max 100 characters), employee_code, dcr_date (YYYY-MM-DD), both locations and distance_km. The employee/date must identify one existing editable DCR. Optional patch and customer codes must match the DCR territory and patch assignments. Imported segments are manual and do not replace generated segments. Reuse the same external_reference for updates.'],
            ['Approvals and access', 'Your existing permissions, territory access and approval rules apply. Approved master records cannot be updated through this import. No approval status or audit fields can be uploaded. Audit fields are set from the importing user.'],
            ['Distances and processing', 'Workbook distances are manual. Import does not call Google or auto-generate extra fare rows when attaching customers. Use the fare chart Google Routes action separately if needed. Workbooks over 100 rows run on the configured background queue; a queue worker must be running.'],
        ];
    }

    public function write(string $path, bool $historical = false): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $heading = (new Style)->setFontBold()->setBackgroundColor('17365D')->setFontColor('FFFFFF');
        $text = (new Style)->setFormat('@');
        try {
            $first = true;
            foreach (self::COLUMNS as $sheet => $columns) {
                if (($sheet === 'Travel Segments') !== $historical) {
                    continue;
                }
                if (! $first) {
                    $writer->addNewSheetAndMakeItCurrent();
                }
                $first = false;
                $writer->getCurrentSheet()->setName($sheet)->setColumnWidthForRange(25, 1, count($columns));
                $writer->addRow(Row::fromValues($columns, $heading));
                $writer->addRow(Row::fromValues(array_fill(0, count($columns), ''), $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions')->setColumnWidth(28, 1);
            $writer->getCurrentSheet()->setColumnWidth(110, 2);
            foreach ($this->instructions() as $instruction) {
                $writer->addRow(Row::fromValues($instruction, (new Style)->setShouldWrapText())->setHeight(65));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Examples')->setColumnWidthForRange(25, 1, 14);
            $examples = [
                'Patches' => ['PATCH-01', 'Central Route', 'TERR-01', 'Ahmedabad', 'Navrangpura', '380009', '#123456', 'Example only'],
                'Patch Customers' => ['PATCH-01', 'CUST-001', '1', '12'],
                'Standard Fares' => ['TERR-01', 'PATCH-01', 'Ahmedabad', 'Navrangpura', '380009', 'Ahmedabad', 'Paldi', '380007', '12', '100', '', '1'],
                'Travel Segments' => ['LEGACY-0001', 'EMP-001', '2026-01-15', 'PATCH-01', '', '', 'Ahmedabad', 'Navrangpura', '380009', 'Ahmedabad', 'Paldi', '380007', '', '12'],
            ];
            foreach ($examples as $sheet => $example) {
                if (($sheet === 'Travel Segments') !== $historical) {
                    continue;
                }
                $writer->addRow(Row::fromValues([$sheet.' — fictitious example; replace with your records'], $heading));
                $writer->addRow(Row::fromValues(self::COLUMNS[$sheet], $heading));
                $writer->addRow(Row::fromValues($example, $text));
            }
        } finally {
            $writer->close();
        }
    }
}
