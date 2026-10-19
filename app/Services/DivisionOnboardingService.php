<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\Tenant;
use App\Models\TypeMaster;
use App\Services\DataImport\SpreadsheetFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DivisionOnboardingService
{
    public const COLUMNS = ['code', 'name', 'description'];

    public function readFile(string $path, bool $isWorkbook = false): array
    {
        $this->authorize();
        $csv = null;
        $handle = null;
        try {
            if ($isWorkbook) {
                $csv = app(SpreadsheetFile::class)->toCsv($path, 'Divisions');
                $path = $csv;
            }
            $handle = fopen($path, 'r');
            $headers = array_map(fn ($value): string => strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))), fgetcsv($handle, escape: '') ?: []);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['code', 'name'], $headers) || array_diff($headers, self::COLUMNS)) {
                throw ValidationException::withMessages(['divisionFile' => 'Use the division template headers: code, name, description.']);
            }
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['divisionFile' => 'Row '.(count($rows) + 2).' has an incorrect number of columns.']);
                }
                $rows[] = array_combine($headers, $values);
                if (count($rows) > 500) {
                    throw ValidationException::withMessages(['divisionFile' => 'Import up to 500 divisions at a time.']);
                }
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['divisionFile' => 'Cannot read the file. Use the template and keep the Divisions worksheet name.']);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($csv !== null) {
                unlink($csv);
            }
        }
    }

    public function preview(array $rows): array
    {
        $this->authorize();
        $rows = array_map(fn (array $row): array => [
            'code' => strtoupper(trim((string) ($row['code'] ?? ''))),
            'name' => trim((string) ($row['name'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
        ], array_values($rows));
        $rows = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.code' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'rows.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'rows.*.description' => ['nullable', 'string', 'max:5000'],
        ])->validate()['rows'];
        $errors = [];
        foreach ($rows as $index => &$row) {
            $record = OrganizationalUnit::withTrashed()->with('typeMaster')->where('code', $row['code'])->first();
            if ($record && ($record->trashed() || $record->typeMaster?->name !== 'Division' || $record->typeMaster?->typeable_type !== OrganizationalUnit::class || $record->name !== $row['name'] || (string) $record->description !== $row['description'])) {
                $errors['rows.'.$index.'.code'] = 'Row '.($index + 2).': '.$row['code'].' conflicts with an existing organizational unit. Edit it in Organization Structure first.';
            }
            if (OrganizationalUnit::withTrashed()->where('name', $row['name'])->when($record, fn ($query) => $query->whereKeyNot($record->id))->exists()) {
                $errors['rows.'.$index.'.name'] = 'Row '.($index + 2).': this organizational-unit name is already in use.';
            }
            $row['action'] = $record ? 'Keep existing' : 'Create';
        }
        unset($row);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    public function import(array $rows): int
    {
        $this->authorize();

        return DB::transaction(function () use ($rows): int {
            $organization = app(CompanyOnboardingService::class)->organization();
            Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $rows = $this->preview($rows);
            $type = TypeMaster::firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
            $created = 0;
            foreach ($rows as $row) {
                if ($row['action'] === 'Keep existing') {
                    continue;
                }
                OrganizationalUnit::create([
                    'code' => $row['code'], 'name' => $row['name'],
                    'description' => $row['description'] ?: null, 'type_master_id' => $type->id,
                    'is_active' => true,
                ]);
                $created++;
            }

            return $created;
        });
    }

    private function authorize(): void
    {
        $service = app(CompanyOnboardingService::class);
        $service->authorize();
        abort_unless(Tenant::current()?->accountIsConfigured() ?? true, 409);
        abort_unless($service->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
        if (! Organization::divisionsEnabled()) {
            throw ValidationException::withMessages(['divisions' => 'Divisions are disabled. Enable them in Company information before importing divisions.']);
        }
    }
}
