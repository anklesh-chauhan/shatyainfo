<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approval\OnboardingApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyOnboardingService
{
    /** @return array<string, bool|int> */
    public function checklist(): array
    {
        $organization = $this->organization();
        $setup = $organization?->metadata['company_setup'] ?? [];
        $company = (bool) ($setup['organization_saved'] ?? false);
        $units = OrganizationalUnit::query()->count();
        $employees = Employee::query()->count();

        return [
            'company' => $company,
            'structure' => $units > 0,
            'employees' => $employees > 0,
            'approvals' => (bool) ($setup['approvals_applied'] ?? false),
            'review' => $company && $employees > 0 && (bool) ($setup['completed'] ?? false),
            'unit_count' => $units,
            'employee_count' => $employees,
        ];
    }

    public function finish(): void
    {
        $checklist = $this->checklist();
        if (! $checklist['company'] || ! $checklist['employees']) {
            throw ValidationException::withMessages(['checklist' => 'Save company information and add at least one employee before finishing setup.']);
        }
        DB::transaction(function (): void {
            $organization = $this->organization();
            abort_unless($organization, 422);
            app(OnboardingApprovalService::class)->apply($organization);
            $this->mark('completed');
        });
    }

    public const EMPLOYEE_COLUMNS = ['employee_id', 'first_name', 'last_name', 'email', 'mobile_number'];

    public function authorize(): void
    {
        abort_unless(Tenant::checkCurrent() && auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']), 403);
    }

    public function organization(): ?Organization
    {
        $this->authorize();

        return Organization::query()->orderBy('id')->first();
    }

    public function saveOrganization(array $data): Organization
    {
        $this->authorize();
        abort_unless((Tenant::current()?->accountIsConfigured() ?? true), 409, 'Your account is still being configured.');
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gst_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_person_email' => ['nullable', 'email', 'max:255'],
            'contact_person_phone' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $organization = $this->organization() ?? new Organization;
        $organization->fill($data);
        $metadata = $organization->metadata ?? [];
        $metadata['company_setup']['organization_saved'] = true;
        $organization->metadata = $metadata;
        $organization->save();

        return $organization;
    }

    public function mark(string $key): void
    {
        $this->authorize();
        abort_unless((Tenant::current()?->accountIsConfigured() ?? true), 409, 'Your account is still being configured.');
        $organization = $this->organization();
        abort_unless($organization, 422, 'Save organization information first.');
        $metadata = $organization->metadata ?? [];
        $metadata['company_setup'][$key] = true;
        $organization->metadata = $metadata;
        $organization->save();
    }

    public function parseCsv(string $path): array
    {
        $this->authorize();

        return $this->readCsv($path);
    }

    public function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
        }
        try {
            $header = fgetcsv($handle, escape: '');
            $header = array_map(fn ($value) => trim(ltrim((string) $value, "\xEF\xBB\xBF")), $header ?: []);
            if (count($header) !== count(array_unique($header)) || array_diff(self::EMPLOYEE_COLUMNS, $header)) {
                throw ValidationException::withMessages(['file' => 'Use the employee template headers.']);
            }
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    throw ValidationException::withMessages(['file' => 'CSV row '.(count($rows) + 2).' has an incorrect number of columns.']);
                }
                $row = array_combine($header, $values);
                $rows[] = array_intersect_key(array_map(fn ($value) => trim((string) $value), $row), array_flip(self::EMPLOYEE_COLUMNS));
                if (count($rows) > 500) {
                    throw ValidationException::withMessages(['file' => 'Import up to 500 employees at a time.']);
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function saveEmployees(array $rows): int
    {
        $this->authorize();
        abort_unless((Tenant::current()?->accountIsConfigured() ?? true), 409, 'Your account is still being configured.');
        abort_unless($this->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save organization information first.');
        $rows = array_map(function (array $row): array {
            $row = array_intersect_key($row, array_flip(self::EMPLOYEE_COLUMNS));
            foreach ($row as $key => $value) {
                $row[$key] = trim((string) $value);
            }
            $row['email'] = empty($row['email']) ? null : strtolower($row['email']);

            return $row;
        }, array_values($rows));
        $validated = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.employee_id' => ['required', 'string', 'max:20', 'distinct:ignore_case', Rule::unique(Employee::class, 'employee_id')],
            'rows.*.first_name' => ['required', 'string', 'max:50'],
            'rows.*.last_name' => ['required', 'string', 'max:50'],
            'rows.*.email' => ['nullable', 'email', 'max:100', 'distinct:ignore_case', Rule::unique(Employee::class, 'email')],
            'rows.*.mobile_number' => ['required', 'string', 'max:100', 'distinct', Rule::unique(Employee::class, 'mobile_number')],
        ])->validate();

        return DB::transaction(function () use ($validated): int {
            foreach ($validated['rows'] as $row) {
                $employee = new Employee($row);
                $employee->forceFill([
                    'is_active' => true,
                    'login_id' => $row['email'] ? User::query()->where('email', $row['email'])->value('id') : null,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ])->saveQuietly();
            }

            return count($validated['rows']);
        });
    }
}
