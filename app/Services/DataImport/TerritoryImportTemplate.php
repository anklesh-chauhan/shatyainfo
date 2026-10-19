<?php

namespace App\Services\DataImport;

use App\Services\TerritoryOnboardingService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class TerritoryImportTemplate
{
    public function write(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $headers = TerritoryOnboardingService::COLUMNS;
            $heading = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText();
            $text = (new Style)->setFontColor('1F4E78')->setFormat('@');
            $writer->getCurrentSheet()->setName('Territories')->setColumnWidthForRange(28, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $heading));
            for ($index = 0; $index < 50; $index++) {
                $writer->addRow(Row::fromValues(array_fill(0, count($headers), ''), $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
            $writer->getCurrentSheet()->setColumnWidth(30, 1);
            $writer->getCurrentSheet()->setColumnWidth(105, 2);
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(['TERRITORY ONBOARDING', 'Instructions and validation rules'], $heading));
            $body = (new Style)->setShouldWrapText();
            foreach ($this->instructions() as $instruction) {
                $writer->addRow(Row::fromValues($instruction, $body)->setHeight(58));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Examples')->setColumnWidthForRange(28, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $heading));
            foreach ([['MUMBAI', 'Mumbai', 'WEST', 'active', 'Child territory', 'DIV-WEST', 'Mumbai|Andheri|400058', 'West Division'], ['WEST', 'West', '', 'active', 'Root territory', 'DIV-WEST', 'Mumbai|Andheri|400058;Pune|Shivajinagar|411005', 'West Division'], ['PUNE', 'Pune', 'WEST', 'inactive', 'Inactive example', 'DIV-WEST', 'Pune|Shivajinagar|411005', 'West Division']] as $example) {
                $writer->addRow(Row::fromValues($example, $text));
            }
        } finally {
            $writer->close();
        }
    }

    public function instructions(): array
    {
        return [
            ['Before you begin', 'Divisions can be created directly from this file. Supply division_codes and matching division_names. Existing divisions are reused by code. If your company has no divisions, select No in Company information and leave both columns blank. City and area references must already exist.'],
            ['1. Prepare', 'Fill the Territories sheet only. Keep its name and column headers unchanged. Instructions and Examples are not imported. Replace examples with your own data; do not upload fictitious territories.'],
            ['2. Upload and preview', 'Save as .xlsx or UTF-8 CSV. Upload up to 500 data rows and 2 MB. Select Preview territories and check names, parent codes, status and the Create / Keep existing actions.'],
            ['3. Confirm import', 'Select Import reviewed territories only after checking the preview. The entire import is saved together. If validation fails, no territories are saved. Correct the reported rows and preview again.'],
            ['4. Add employees', 'Import territories before employees. In the employee template, use territory_codes such as WEST;MUMBAI to link territories to a position. These assignments are shared by employees in that position.'],
            ['Hierarchy order', 'Parents and children may appear in any row order. A parent code must exist in this upload or the company. Self-parenting and circular hierarchies are rejected.'],
            ['Existing territories', 'Codes match without case sensitivity and new codes are stored in uppercase. An identical existing record is kept. Conflicting names, parents, status, descriptions, division links or city/area links are rejected; edit them in Territories first. Deleted codes cannot be reused here.'],
            ['Blank cells', 'Blank parent_code means a root territory; blank status means active; blank description means no description. These values must also match when re-importing existing codes.'],
            ['Additional details', 'Optional region, type and position links can be managed in the existing Territories admin page after import. They are not changed by this template.'],
            ['Data format', 'Format codes as Text to preserve leading zeros. Enter values, not formulas. Do not include database IDs. Separate multiple division_codes or cities_and_areas with semicolons. For each location use City|Area|PIN. Use one parent_code only.'],
            ['code', 'Required unique territory code, maximum 255 characters. Each code may appear only once in an upload.'],
            ['name', 'Required unique territory name, maximum 255 characters.'],
            ['division_codes', 'Required when the company uses divisions. Separate codes with semicolons: DIV-WEST;DIV-SOUTH. Existing codes are reused; new codes require corresponding names. Blank is allowed when divisions are disabled and preserves existing links.'],
            ['division_names', 'Names in the same order as division_codes: West Division;South Division. Required for each new division. For existing codes, omit names or supply their exact existing names. Repeat a new code with the same name on every row where it appears. Conflicting names, deleted codes and non-division codes are rejected. New divisions and territories save together; any error saves nothing.'],
            ['cities_and_areas', 'Required. One or more existing locations written as City|Area|PIN, separated by semicolons: Mumbai|Andheri|400058;Pune|Shivajinagar|411005. Use exact city and area names from the Cities and Areas selector. Missing or ambiguous matches are rejected.'],
            ['parent_code', 'Optional parent territory code, maximum 255 characters. Leave blank for a root territory. Reference a code, not a name or database ID.'],
            ['status', 'Optional: active or inactive. Blank defaults to active.'],
            ['description', 'Optional description, maximum 5,000 characters.'],
        ];
    }
}
