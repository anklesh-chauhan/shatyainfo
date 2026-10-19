<?php

namespace App\Services\DataImport;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

class EmployeeImportTemplate
{
    public function write(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        try {
            $columns = EmployeeOnboardingImporter::columns();
            $headers = array_map(fn (ImportColumnDefinition $column): string => $column->header, $columns);
            $headerStyle = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('17365D')->setShouldWrapText();
            $textStyle = (new Style)->setFontColor('1F4E78')->setFormat('@');
            $writer->getCurrentSheet()->setName('Employees')->setColumnWidthForRange(24, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $headerStyle)->setHeight(42));
            for ($index = 0; $index < 50; $index++) {
                $writer->addRow(Row::fromValues(array_fill(0, count($headers), ''), $textStyle));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
            $writer->getCurrentSheet()->setColumnWidth(32, 1);
            $writer->getCurrentSheet()->setColumnWidth(105, 2);
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues(['EMPLOYEE ONBOARDING', 'Instructions and validation rules'], $headerStyle)->setHeight(28));
            $bodyStyle = (new Style)->setShouldWrapText();
            foreach ($this->instructions() as $instruction) {
                $writer->addRow(Row::fromValues($instruction, $bodyStyle)->setHeight(48));
            }
            $writer->addRow(Row::fromValues(['COLUMN', 'What to enter'], $headerStyle)->setHeight(28));
            foreach ($this->columnInstructions() as $name => $instruction) {
                $writer->addRow(Row::fromValues([$name, $instruction], $bodyStyle)->setHeight(48));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName('Examples')->setColumnWidthForRange(24, 1, count($headers));
            $writer->getCurrentSheet()->setSheetView((new SheetView)->setFreezeRow(2));
            $writer->addRow(Row::fromValues($headers, $headerStyle)->setHeight(42));
            foreach ($this->examples() as $example) {
                $writer->addRow(Row::fromValues(array_map(fn (string $header): string => $example[$header] ?? '', $headers), $textStyle)->setHeight(28));
            }
        } finally {
            $writer->close();
        }
    }

    /** @return list<array{string, string}> */
    public function instructions(): array
    {
        return [
            ['1. Prepare', 'Fill the Employees sheet only. Instructions and Examples are never imported. Keep the column headers unchanged. The Employees sheet may be moved to any tab position.'],
            ['2. One row per assignment', 'Use one row per employee-position assignment. Repeat the employee code for additional positions; personal details must agree. Give exactly one position Yes for is_primary_position.'],
            ['3. Order does not matter', 'Managers can appear before or after their team. References must point to an existing company position/employee or one defined in this upload. Do not reference database row IDs or names.'],
            ['4. Import and review', 'Upload .xlsx or UTF-8 CSV. The entire employee file is saved together; any validation error saves nothing. Fix the reported row and upload again. Review the hierarchy shown after a successful import.'],
            ['Position is authoritative', 'reports_to_position_code defines the organization hierarchy. The manager is the single active employee occupying the parent position of the employee\'s primary position. Job role level does not create reporting relationships.'],
            ['Expected manager is a check', 'reports_to_employee_code checks the resolved manager; it does not override the hierarchy or select between multiple occupants. For multiple occupants, correct assignments or use separate manager positions.'],
            ['Vacant reporting positions', 'A parent position may have no active employee. Import succeeds with Reporting position vacant, unless an expected manager was supplied. Create unoccupied positions in Positions before referencing them.'],
            ['Top-level employees', 'For a new top-level position, leave reports_to_position_code and reports_to_employee_code blank. For an existing position, use reporting_action = clear to remove its parent.'],
            ['Blank cells', 'Blank means preserve existing values, assignments and reporting links. On a new position, blank reporting means no parent. New employees still need their required personal details and a primary assignment.'],
            ['Change primary position', 'Set Yes on the new primary position; other existing assignments become secondary. Set assignment_action = remove on a separate row to detach an old position. Exactly one primary must remain.'],
            ['Replace a manager', 'Assign the replacement to the manager position and remove the outgoing employee from it in the same upload. Give the outgoing employee another primary position. Dependent employee managers refresh automatically.'],
            ['Clear vs remove', 'reporting_action = clear removes the parent link for the position (affects everyone in that position). assignment_action = remove detaches only this employee from the position. Neither deletes a record.'],
            ['Hierarchy validation', 'Self-reporting, circular position/employee chains, missing codes, conflicting shared position details and ambiguous manager occupants are rejected. No first matching manager is chosen.'],
            ['Shared setup', 'Departments, grades and job titles are reused by name; job roles and org units by name; positions by code. Existing conflicting details must be corrected separately or given a different name/code.'],
            ['Data format', 'Format codes and phone numbers as Text to preserve leading zeros. Use YYYY-MM-DD for dates and Yes/No for primary flags. Enter values, not formulas. Examples are fictitious and must be replaced.'],
            ['Permissions and accounts', 'You need permission to create/update employees and any setup/position records changed. Approval locks still apply. This import does not create login accounts or send invitations.'],
        ];
    }

    /** @return array<string, string> */
    public function columnInstructions(): array
    {
        return [
            'employee_id' => 'Required unique HR employee code, maximum 20 characters. Repeated rows for the same employee must have consistent personal details.',
            'first_name' => 'Required for a new employee; maximum 50 characters. Blank preserves the existing name.',
            'last_name' => 'Required for a new employee; maximum 50 characters. Blank preserves the existing name.',
            'mobile_number' => 'Required and unique for a new employee; enter as Text, maximum 100 characters.',
            'email' => 'Optional valid unique email, maximum 100 characters. No invitation is sent.',
            'department' => 'Optional department name, maximum 100 characters; creates or reuses the department.',
            'job_role' => 'Required when defining a new position. Shared role name, for example Area Sales Manager.',
            'job_role_level' => 'Positive whole number; new roles default to 1. This is descriptive hierarchy level, not a reporting link.',
            'position' => 'Required when defining a new position; specific seat name, for example Area Sales Manager - Mumbai.',
            'position_code' => 'Unique position code, maximum 255 characters. Required for assignment/reporting changes. Existing position codes can be used without repeating name/job role.',
            'territory_codes' => 'Optional existing territory codes separated by semicolons, for example WEST;MUMBAI. Upload territories first. Assigns territories to the position, shared by all its employees. Blank preserves assignments. Conflicting existing assignments must be edited in Positions.',
            'grade' => 'Optional grade name, maximum 50 characters; reused by name and must match the department if already defined.',
            'job_title' => 'Optional title; reused by title and must match the department if already defined.',
            'org_unit' => 'Optional employee/position organizational unit name. Existing names must be unambiguous. This does not change the department\'s organizational unit; a department may be shared across employee org units. Division is derived automatically: a Division unit selects itself; a child unit uses its nearest Division ancestor. Configure parent units under Org Units before importing child units. Units outside a Division leave Division blank.',
            'org_unit_type' => 'Existing organizational-unit type, for example Division, Head Office, Plant or Department. Defaults to Division when an org unit is supplied.',
            'hire_date' => 'Optional date in YYYY-MM-DD format. Enter on the primary assignment row; blank preserves the existing date.',
            'employment_type' => 'Optional: Permanent, Contract, Part-Time, Intern, Temporary or Consultant. Enter on the primary assignment row.',
            'is_primary_position' => 'Yes or No. Exactly one final primary position per imported employee. Blank keeps an existing flag; a new assignment defaults to secondary.',
            'reports_to_position_code' => 'Parent position code. Must exist in this file or company. A nonblank code sets the link; blank preserves it unless reporting_action is clear.',
            'reports_to_employee_code' => 'Optional expected manager HR code for this employee\'s primary assignment. Must match the single active occupant of the reporting position. Not a manager override.',
            'reporting_action' => 'Blank, set or clear. set requires reports_to_position_code; clear requires that code blank. Leave blank with a code to set it automatically.',
            'assignment_action' => 'Blank or assign adds/keeps this employee-position assignment. remove detaches it. A removed assignment cannot have is_primary_position = Yes or an expected manager.',
        ];
    }

    /** @return list<array<string, string>> */
    public function examples(): array
    {
        $base = ['department' => 'Sales', 'org_unit' => 'West Division', 'org_unit_type' => 'Division', 'is_primary_position' => 'Yes', 'hire_date' => '2026-10-01', 'employment_type' => 'Permanent'];

        return [
            [...$base, 'employee_id' => 'EMP004', 'first_name' => 'Asha', 'last_name' => 'Shah', 'mobile_number' => '9000000004', 'job_role' => 'Medical Representative', 'job_role_level' => '4', 'position' => 'Medical Representative - Mumbai 01', 'position_code' => 'MR-MUM-01', 'reports_to_position_code' => 'ASM-MUMBAI', 'reports_to_employee_code' => 'EMP003'],
            [...$base, 'employee_id' => 'EMP001', 'first_name' => 'Ravi', 'last_name' => 'Shah', 'mobile_number' => '9000000001', 'job_role' => 'Sales Head', 'job_role_level' => '1', 'position' => 'Sales Head', 'position_code' => 'SALES-HEAD'],
            [...$base, 'employee_id' => 'EMP003', 'first_name' => 'Meera', 'last_name' => 'Patel', 'mobile_number' => '9000000003', 'job_role' => 'Area Sales Manager', 'job_role_level' => '3', 'position' => 'Area Sales Manager - Mumbai', 'position_code' => 'ASM-MUMBAI', 'reports_to_position_code' => 'RSM-WEST', 'reports_to_employee_code' => 'EMP002'],
            [...$base, 'employee_id' => 'EMP002', 'first_name' => 'Kiran', 'last_name' => 'Patel', 'mobile_number' => '9000000002', 'job_role' => 'Regional Sales Manager', 'job_role_level' => '2', 'position' => 'Regional Sales Manager - West', 'position_code' => 'RSM-WEST', 'reports_to_position_code' => 'SALES-HEAD', 'reports_to_employee_code' => 'EMP001'],
        ];
    }
}
