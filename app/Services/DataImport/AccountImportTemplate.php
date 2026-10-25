<?php

namespace App\Services\DataImport;

use App\Models\AccountMaster;
use App\Models\AccountOwnership;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\Address;
use App\Models\ContactDetail;
use App\Models\Department;
use App\Models\Designation;
use App\Models\IndustryType;
use App\Models\OrganizationalUnit;
use App\Models\Patch;
use App\Models\RatingType;
use App\Models\Region;
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
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2)->setFreezeColumn('C'));
            $writer->addRow(new Row(array_map(function (string $column) use ($heading): StringCell {
                $style = clone $heading;
                $style->setBackgroundColor(match (true) {
                    in_array($column, ['external_reference', 'name', 'account_type', 'owner_email']) => '17365D',
                    str_starts_with($column, 'contact_') => '38761D',
                    str_starts_with($column, 'bank_') => '674EA7',
                    str_starts_with($column, 'credit_') => 'A64D79',
                    str_starts_with($column, 'gst_'), str_contains($column, 'tds'), in_array($column, ['tan_number', 'cin', 'pan_number']) => '8A5A00',
                    default => '32657A',
                });

                return new StringCell($column, $style);
            }, AccountOnboardingService::COLUMNS)));
            for ($index = 0; $index < 20; $index++) {
                $writer->addRow(Row::fromValues(array_fill(0, count(AccountOnboardingService::COLUMNS), ''), $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions')->setColumnWidth(110, 2);
            $writer->getCurrentSheet()->setColumnWidth(28, 1);
            $writer->addRow(Row::fromValues(['ACCOUNT IMPORT', 'Instructions'], $heading));
            foreach ([
                ['Workflow', 'Fill only Accounts. Upload XLSX or UTF-8 CSV, up to 500 accounts and 2 MB. Preview, correct every error, then confirm. No records are saved by preview. Import is all-or-nothing.'],
                ['Required columns', 'external_reference, name, account_type, owner_email are required on every row. Use exact type names and owner login emails from References. Masters and users must exist first.'],
                ['Stable identity', 'external_reference is your stable source system ID, separate from the optional account_code. It is uppercased and unique per company. Use a prefix for different sources, e.g. ERP:000123. Allowed: letters, digits, underscore, dot, colon, slash and hyphen. Keep it unchanged on every rerun.'],
                ['Existing accounts', 'An existing external reference updates that account. Names never identify records. Accounts created manually without a reference must be reviewed and assigned a reference in Accounts before import. Deleted references cannot be reused. Review possible duplicate names in the preview.'],
                ['Blank cells', 'Blank optional fields preserve existing values. New accounts default to Prospect. Patch codes add links without removing existing ones. This importer does not clear fields or delete records.'],
                ['Status and ratings', 'status_code is the lifecycle code, e.g. prospect or active. rating and segment are potential rating names. Active records must meet the type activation requirements. Incomplete new records should use prospect. Commission is 0 to 100 percent.'],
                ['Addresses', 'Provide address_type, street, city, area and pin_code together to create/update the single primary address. Use an existing city name. Match by city and PIN only: reuse the oldest matching area regardless of the supplied area name, or create the supplied area during import if none exists. PINs may be shared by multiple areas. Other addresses are preserved.'],
                ['Relationships', 'parent_reference, institution_reference and supplier_reference use external references from this file or existing accounts. Row order does not matter. Self-links, missing references and parent cycles are rejected.'],
                ['Territories and patches', 'Use an existing territory_code. Separate patch_codes with semicolons. Existing patches are reused; missing patches are created on confirmation using the code as the name, the account territory and primary address location when available. New patches require a territory. Deleted patches must be restored first. Preview creates nothing.'],
                ['Segmentation', 'One division profile per account per file. segment and profile_from (YYYY-MM-DD) are required together. Blank division_code means account-wide. Reuse the same external reference in a later file to add another division.'],
                ['Potential', 'potential_value requires potential_unit_code, potential_period (monthly, quarterly, annual) and assessed_on (YYYY-MM-DD, not future). Revenue also requires a three-letter currency such as INR.'],
                ['Periods and reruns', 'A profile matches account + division + profile_from. A target matches account + target_from. Matching periods are updated. Blank end date preserves an existing end date, or creates an open-ended period. Different periods must not overlap. Close old periods in Accounts before adding new ones.'],
                ['Call targets', 'calls_per_month is an account-wide integer from 0 to 1000; 0 is an explicit zero target. target_from must be the first day of a month; target_to, if supplied, must be the last day. Blank target fields preserve existing targets.'],
                ['References', 'References contains your company values at download time. Reporting masters use codes; account/address types and potential ratings use names. Keep identifiers as Text to preserve leading zeros. Use values, not formulas.'],
                ['Account fields', 'Optional: account_subtype (child of account_type), alias, secondary_email, website, no_of_employees, twitter, linked_in, annual_revenue, sic_code, ticker_symbol, description, industry, region, account_ownership and ref_contact_email. Reference names and emails must match exactly one existing master. URLs require http:// or https://.'],
                ['Contact details', 'One contact per row. Supply contact_first_name and contact_email or contact_mobile_number. Existing linked contacts match by email or mobile; shared contacts must be edited separately. Optional contact_ fields include salutation, last name, birthday, designation, department, social links and notes.'],
                ['Bank details', 'Supply bank_name and bank_account_number together. All bank_account_ fields are optional except the number. Numbers match existing bank records for this account; a number used elsewhere is rejected. Keep numbers as Text. One bank account per row; import another file to add more.'],
                ['Credit and GST', 'Credit details require credit_type: Days, Limit or Both. GST details require gst_number, gst_state_name, gst_state_code and gst_type on creation. GST links to the primary address if present. Blank optional fields preserve existing values.'],
                ['Statutory details', 'Optional TAN, CIN and TDS fields are imported as entered. tds_rate is 0–100; is_tds_deduct and is_tds_compulsory accept yes/no or 1/0.'],
                ['Account code', 'Optional account_code accepts your own unique account code. Leave it blank to generate a code for a new account or preserve an existing code on update. external_reference is still required as the stable import identity. Field-route customer_code fields accept either account_code or external_reference; ambiguous matches are rejected.'],
                ['System fields', 'Database IDs, approval decisions, audit fields, assignment history and verification flags are not uploaded. One primary address, contact, bank account and division profile per row; repeat files can add contacts, bank accounts and divisions.'],
            ] as $instruction) {
                $writer->addRow(Row::fromValues($instruction, $text)->setHeight(65));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('References')->setColumnWidthForRange(38, 1, 3);
            $writer->addRow(Row::fromValues(['Field', 'Value to use', 'Description'], $heading));
            $sources = [
                ['industry', IndustryType::query(), 'name', 'name'],
                ['region', Region::query(), 'name', 'name'],
                ['account_ownership', AccountOwnership::query(), 'name', 'name'],
                ['ref_contact_email', ContactDetail::whereNotNull('email'), 'email', 'first_name'],
                ['contact_designation', Designation::query(), 'name', 'name'],
                ['contact_department', Department::query(), 'name', 'name'],
                ['account_subtype', TypeMaster::where('typeable_type', AccountMaster::class)->whereNotNull('parent_id'), 'name', 'name'],
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
            $writer->addNewSheetAndMakeItCurrent()->setName('Field Guide')->setColumnWidthForRange(32, 1, 3);
            $writer->addRow(Row::fromValues(['Column', 'Required', 'Field group'], $heading));
            foreach (AccountOnboardingService::COLUMNS as $column) {
                $group = match (true) {
                    str_starts_with($column, 'contact_') => 'Contact',
                    str_starts_with($column, 'bank_') => 'Bank',
                    str_starts_with($column, 'credit_') => 'Credit',
                    str_starts_with($column, 'gst_'), $column === 'pan_number' => 'GST',
                    str_contains($column, 'tds'), in_array($column, ['tan_number', 'cin']) => 'Statutory',
                    default => 'Account / reporting',
                };
                $writer->addRow(Row::fromValues([$column, in_array($column, ['external_reference', 'name', 'account_type', 'owner_email']) ? 'Yes' : 'Optional / see Instructions', $group], $text));
            }
        } finally {
            $writer->close();
        }
    }
}
