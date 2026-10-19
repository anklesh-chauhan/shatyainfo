<?php

namespace App\Services\DataImport;

use App\Services\DivisionOnboardingService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class DivisionImportTemplate
{
    public function write(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $headers = DivisionOnboardingService::COLUMNS;
            $heading = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText();
            $text = (new Style)->setFontColor('1F4E78')->setFormat('@');
            $writer->getCurrentSheet()->setName('Divisions')->setColumnWidthForRange(28, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $heading));
            for ($index = 0; $index < 50; $index++) {
                $writer->addRow(Row::fromValues(array_fill(0, count($headers), ''), $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
            $writer->getCurrentSheet()->setColumnWidth(30, 1);
            $writer->getCurrentSheet()->setColumnWidth(105, 2);
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(['DIVISION ONBOARDING', 'Instructions and validation rules'], $heading));
            $body = (new Style)->setShouldWrapText();
            foreach ($this->instructions() as $instruction) {
                $writer->addRow(Row::fromValues($instruction, $body)->setHeight(58));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Examples')->setColumnWidthForRange(28, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $heading));
            foreach ([['DIV-WEST', 'West Division', 'Western business division'], ['DIV-SOUTH', 'South Division', 'Southern business division']] as $example) {
                $writer->addRow(Row::fromValues($example, $text));
            }
        } finally {
            $writer->close();
        }
    }

    public function instructions(): array
    {
        return [
            ['1. Prepare', 'Fill only the Divisions worksheet. Keep its name and headers. Instructions and Examples are not imported. Replace examples with your own data.'],
            ['2. Upload', 'Use .xlsx or UTF-8 CSV, up to 500 rows and 2 MB. Preview the records, then confirm the import. Any invalid row prevents the entire import from saving.'],
            ['3. Link territories', 'After importing divisions, use their codes in the territory template division_codes column. Separate multiple division codes with semicolons.'],
            ['Company without divisions', 'Select No for Does your company use divisions? in Company information. Division imports are then disabled and territory division links are optional. Existing records and links are preserved.'],
            ['Existing records', 'Identical records are kept. Conflicting codes or names, deleted records and codes belonging to another organizational-unit type are rejected. Edit those records in Organization Structure first.'],
            ['Data format', 'Keep codes as Text to preserve leading zeros. Codes match without case sensitivity and new codes are stored in uppercase. Enter values, not formulas.'],
            ['code', 'Required unique division code, maximum 255 characters. Use this code when importing territories.'],
            ['name', 'Required unique division name, maximum 255 characters.'],
            ['description', 'Optional description, maximum 5,000 characters. Blank means no description and must match when repeating an existing division.'],
            ['Division type', 'New records are created as active organizational units of type Division. Additional hierarchy and settings can be edited in Organization Structure.'],
        ];
    }
}
