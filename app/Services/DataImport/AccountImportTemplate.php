<?php

namespace App\Services\DataImport;

use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\Address;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\RatingType;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Models\User;
use App\Services\AccountOnboardingService;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class AccountImportTemplate
{
    public function write(string $path): void
    {
        app(AccountOnboardingService::class)->authorize();
        $writer = new Writer;
        $writer->openToFile($path);
        $heading = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText();
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        try {
            $writer->getCurrentSheet()->setName('Accounts')->setColumnWidthForRange(26, 1, count(AccountOnboardingService::COLUMNS));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(AccountOnboardingService::COLUMNS, $heading));
            for ($index = 0; $index < 20; $index++) {
                $writer->addRow(Row::fromValues(array_fill(0, count(AccountOnboardingService::COLUMNS), ''), $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions')->setColumnWidth(110, 2);
            $writer->getCurrentSheet()->setColumnWidth(28, 1);
            $writer->addRow(Row::fromValues(['ACCOUNT IMPORT', 'Instructions'], $heading));
            foreach ([
                ['Workflow', 'Fill only Accounts. Upload XLSX or UTF-8 CSV, up to 500 accounts and 2 MB. Preview, correct every error, then confirm. No records are saved by preview. Import is all-or-nothing.'],
                ['Required columns', 'external_reference, name, account_type, owner_email are required on every row. Use exact type names and owner login emails from References. Masters and users must exist first.'],
                ['Stable identity', 'external_reference is your source system ID, not the generated account code. It is uppercased and unique per company. Use a prefix for different sources, e.g. ERP:000123. Allowed: letters, digits, underscore, dot, colon, slash and hyphen. Keep it unchanged on every rerun.'],
                ['Existing accounts', 'An existing external reference updates that account. Names never identify records. Accounts created manually without a reference must be reviewed and assigned a reference in Accounts before import. Deleted references cannot be reused. Review possible duplicate names in the preview.'],
                ['Blank cells', 'Blank optional fields preserve existing values. New accounts default to Prospect. Patch codes add links without removing existing ones. This importer does not clear fields or delete records.'],
                ['Status and ratings', 'status_code is the lifecycle code, e.g. prospect or active. rating and segment are potential rating names. Active records must meet the type activation requirements. Incomplete new records should use prospect. Commission is 0 to 100 percent.'],
                ['Addresses', 'Provide address_type, street, city, area and pin_code together to create/update the single primary address. Use existing City / Area / PIN values from your Cities and Areas master. Other addresses are preserved.'],
                ['Relationships', 'parent_reference, institution_reference and supplier_reference use external references from this file or existing accounts. Row order does not matter. Self-links, missing references and parent cycles are rejected.'],
                ['Territories and patches', 'Use existing territory_code and patch_codes, separated by semicolons for patches. Import patches first, or leave patch_codes blank and add them later.'],
                ['Segmentation', 'One division profile per account per file. segment and profile_from (YYYY-MM-DD) are required together. Blank division_code means account-wide. Reuse the same external reference in a later file to add another division.'],
                ['Potential', 'potential_value requires potential_unit_code, potential_period (monthly, quarterly, annual) and assessed_on (YYYY-MM-DD, not future). Revenue also requires a three-letter currency such as INR.'],
                ['Periods and reruns', 'A profile matches account + division + profile_from. A target matches account + target_from. Matching periods are updated. Blank end date preserves an existing end date, or creates an open-ended period. Different periods must not overlap. Close old periods in Accounts before adding new ones.'],
                ['Call targets', 'calls_per_month is an account-wide integer from 0 to 1000; 0 is an explicit zero target. target_from must be the first day of a month; target_to, if supplied, must be the last day. Blank target fields preserve existing targets.'],
                ['References', 'References contains your company values at download time. Reporting masters use codes; account/address types and potential ratings use names. Keep identifiers as Text to preserve leading zeros. Use values, not formulas.'],
            ] as $instruction) {
                $writer->addRow(Row::fromValues($instruction, $text)->setHeight(65));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('References')->setColumnWidthForRange(38, 1, 3);
            $writer->addRow(Row::fromValues(['Field', 'Value to use', 'Description'], $heading));
            $sources = [
                ['account_type', TypeMaster::where('typeable_type', AccountMaster::class), 'name', 'name'],
                ['address_type', TypeMaster::where('typeable_type', Address::class), 'name', 'name'],
                ['owner_email', User::query(), 'email', 'name'],
                ['status_code', AccountStatus::query(), 'code', 'name'],
                ['rating / segment', RatingType::where('is_lifecycle', false), 'name', 'name'],
                ['territory_code', Territory::query(), 'code', 'name'],
                ['patch_codes', Patch::query(), 'code', 'name'],
                ['division_code', OrganizationalUnit::where('is_active', true)->whereHas('typeMaster', fn ($query) => $query->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class)), 'code', 'name'],
            ];
            foreach (['specialty', 'therapy_area', 'potential_unit'] as $kind) {
                $sources[] = [$kind.'_code', AccountReportingMaster::where('kind', $kind)->where('is_active', true), 'code', 'name'];
            }
            foreach ($sources as [$field, $query, $value, $description]) {
                foreach ($query->orderBy('id')->lazy() as $record) {
                    $writer->addRow(new Row(array_map(fn ($cell) => new StringCell((string) $cell, $text), [$field, $record->$value, $record->$description])));
                }
            }
        } finally {
            $writer->close();
        }
    }
}
