<?php

namespace App\Services\DataImport;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader;
use League\Csv\Writer;
use Throwable;
use UnitEnum;

class ResourceCsvImporter
{
    public function import(ImportableModule $module, string $path): ImportResult
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            try {
                $csv = app(SpreadsheetFile::class)->toCsv($path, $module->modelClass === Employee::class ? 'Employees' : null);
            } catch (ImportRowException $exception) {
                return new ImportResult(failed: [['row' => 1, 'message' => $exception->getMessage()]]);
            } catch (Throwable $exception) {
                return new ImportResult(failed: [['row' => 1, 'message' => 'Unable to read this Excel workbook. Upload a valid .xlsx file.']]);
            }

            try {
                return $this->import($module, $csv);
            } finally {
                unlink($csv);
            }
        }
        if (in_array($module->modelClass, [User::class, Tenant::class], true)) {
            return new ImportResult(failed: [[
                'row' => 1,
                'message' => 'This module cannot be imported from a file.',
            ]]);
        }

        try {
            $reader = Reader::createFromPath($path);
            $reader->setHeaderOffset(0);
            $headers = array_map(
                fn (string $header): string => $this->normalizeHeader($header),
                $reader->getHeader(),
            );
        } catch (Throwable) {
            return new ImportResult(failed: [[
                'row' => 1,
                'message' => 'The file needs a header row.',
            ]]);
        }

        if (count($headers) !== count(array_unique($headers))) {
            return new ImportResult(failed: [[
                'row' => 1,
                'message' => 'The header row has duplicate columns.',
            ]]);
        }

        $missing = $this->missingRequiredHeaders($module, $headers);

        if ($module->matchAttribute !== null && ! in_array($module->matchAttribute, $headers, true)) {
            $missing[] = $module->matchAttribute;
        }

        $missing = array_values(array_unique($missing));

        if ($missing !== []) {
            return new ImportResult(failed: [[
                'row' => 1,
                'message' => 'Add these columns: '.implode(', ', $missing).'.',
            ]]);
        }

        if ($module->modelClass === Employee::class) {
            $allowed = array_map(fn (ImportColumnDefinition $column): string => $column->header, $module->columns);
            $unknown = array_diff($headers, $allowed);
            if ($unknown !== []) {
                return new ImportResult(failed: [['row' => 1, 'message' => 'Unknown columns: '.implode(', ', $unknown).'. Use the employee template headers.']]);
            }
            $rows = [];
            foreach ($reader->getRecords() as $offset => $record) {
                $row = [];
                foreach ($record as $header => $value) {
                    $row[$this->normalizeHeader((string) $header)] = is_string($value) ? trim($value) : $value;
                }
                if (! collect($row)->every(fn (mixed $value): bool => blank($value))) {
                    $rows[$offset + 1] = $row;
                }
            }

            return app(EmployeeHierarchyImport::class)->import($rows);
        }

        $result = new ImportResult;

        foreach ($reader->getRecords() as $offset => $record) {
            $rowNumber = is_int($offset) ? $offset + 1 : $result->importedCount() + count($result->failed) + 2;
            $row = [];

            foreach ($record as $header => $value) {
                $row[$this->normalizeHeader((string) $header)] = is_string($value) ? trim($value) : $value;
            }

            if (collect($row)->every(fn (mixed $value): bool => blank($value))) {
                continue;
            }

            try {
                $outcome = DB::transaction(fn (): string => $this->importRow($module, $row));

                if ($outcome === 'updated') {
                    $result->updated++;
                } else {
                    $result->created++;
                }
            } catch (ImportRowException $exception) {
                $result->failed[] = ['row' => $rowNumber, 'message' => $exception->getMessage()];
            } catch (ValidationException $exception) {
                $result->failed[] = [
                    'row' => $rowNumber,
                    'message' => collect($exception->errors())->flatten()->implode(' '),
                ];
            } catch (QueryException $exception) {
                $result->failed[] = [
                    'row' => $rowNumber,
                    'message' => (string) ($exception->errorInfo[2] ?? 'This row could not be saved.'),
                ];
            } catch (Throwable $exception) {
                report($exception);
                $result->failed[] = ['row' => $rowNumber, 'message' => 'This row could not be imported.'];
            }
        }

        return $result;
    }

    public function exampleCsv(ImportableModule $module): string
    {
        $writer = Writer::createFromString();
        $writer->insertOne(array_map(
            fn (ImportColumnDefinition $column): string => $column->header,
            $module->columns,
        ));
        $writer->insertOne(array_map(
            fn (ImportColumnDefinition $column): string => $column->example,
            $module->columns,
        ));

        return $writer->toString();
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function missingRequiredHeaders(ImportableModule $module, array $headers): array
    {
        $missing = [];

        foreach ($module->columns as $column) {
            if (! $column->required) {
                continue;
            }

            if (array_intersect($column->acceptedHeaders(), $headers) === []) {
                $missing[] = $column->header;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importRow(ImportableModule $module, array $row): string
    {
        if ($module->modelClass === Employee::class) {
            return app(EmployeeOnboardingImporter::class)->importRow($row);
        }

        /** @var Model $model */
        $model = new $module->modelClass;
        $attributes = [];
        $matchValue = null;

        foreach ($module->columns as $column) {
            [$value, $headerUsed] = $this->rawValue($row, $column);

            if (blank($value)) {
                continue;
            }

            if ($column->attribute === $module->matchAttribute) {
                $matchValue = $value;
            }

            $attributes[$column->attribute] = $this->castValue($module, $column, (string) $value, $headerUsed, $row);
        }

        if ($module->matchAttribute !== null && blank($matchValue)) {
            throw new ImportRowException(str($module->matchAttribute)->headline().' is required.');
        }

        $existing = null;

        if ($module->matchAttribute !== null) {
            $existing = $model->newQuery()->where($module->matchAttribute, $matchValue)->first();
        }

        if (! $existing) {
            foreach ($module->columns as $column) {
                if ($column->required && ! array_key_exists($column->attribute, $attributes)) {
                    throw new ImportRowException($column->label.' is required.');
                }
            }
        }

        if ($existing) {
            $existing->forceFill($attributes);
            $existing->save();

            return 'updated';
        }

        $record = new $module->modelClass;
        $record->forceFill($attributes);
        $record->save();

        return 'created';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: mixed, 1: ?string}
     */
    private function rawValue(array $row, ImportColumnDefinition $column): array
    {
        foreach ($column->acceptedHeaders() as $header) {
            if (array_key_exists($header, $row) && filled($row[$header])) {
                return [$row[$header], $header];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function castValue(ImportableModule $module, ImportColumnDefinition $column, string $value, ?string $headerUsed, array $row): mixed
    {
        if ($column->type === 'relationship') {
            return $this->resolveRelatedKey($module, $column, $value, $headerUsed, $row);
        }

        return match ($column->type) {
            'boolean' => $this->castBoolean($column, $value),
            'integer' => $this->castInteger($column, $value),
            'number' => $this->castNumber($column, $value),
            'date' => $this->castDate($column, $value, false),
            'datetime' => $this->castDate($column, $value, true),
            'json' => $this->castJson($column, $value),
            'enum' => $this->castEnum($column, $value),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveRelatedKey(ImportableModule $module, ImportColumnDefinition $column, string $value, ?string $headerUsed, array $row): int|string
    {
        $relatedClass = $column->relatedModel;

        if (! is_string($relatedClass) || ! is_a($relatedClass, Model::class, true)) {
            throw new ImportRowException($column->label.' could not be resolved.');
        }

        $byId = $headerUsed === $column->attribute && ctype_digit($value);
        $related = $byId
            ? $relatedClass::query()->find($value)
            : $relatedClass::query()->where($column->relatedLookup, $value)->first();

        if ($related instanceof Model) {
            $key = $related->getKey();

            if ($relatedClass === $module->modelClass && $this->referencesCurrentRow($module, $column, $value, $row)) {
                throw new ImportRowException('A record cannot reference itself.');
            }

            return $key;
        }

        if ($this->referencesCurrentRow($module, $column, $value, $row)) {
            throw new ImportRowException('A record cannot reference itself.');
        }

        if ($relatedClass === $module->modelClass && is_string($column->relatedLookup)) {
            try {
                $created = $relatedClass::query()->firstOrCreate([
                    $column->relatedLookup => $value,
                ]);

                return $created->getKey();
            } catch (QueryException) {
                // The parent needs more than its name. The row fails below.
            }
        }

        throw new ImportRowException($column->label.' "'.$value.'" was not found.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function referencesCurrentRow(ImportableModule $module, ImportColumnDefinition $column, string $value, array $row): bool
    {
        if ($column->relatedModel !== $module->modelClass || $module->matchAttribute === null) {
            return false;
        }

        $current = $row[$module->matchAttribute] ?? null;

        return filled($current) && (string) $current === $value;
    }

    private function castBoolean(ImportColumnDefinition $column, string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => throw new ImportRowException($column->label.' must be yes or no.'),
        };
    }

    private function castInteger(ImportColumnDefinition $column, string $value): int
    {
        if (! preg_match('/^-?\d+$/', $value)) {
            throw new ImportRowException($column->label.' must be a whole number.');
        }

        return (int) $value;
    }

    private function castNumber(ImportColumnDefinition $column, string $value): float
    {
        $normalized = str_replace([',', ' '], '', $value);

        if (! is_numeric($normalized)) {
            throw new ImportRowException($column->label.' must be a number.');
        }

        return (float) $normalized;
    }

    private function castDate(ImportColumnDefinition $column, string $value, bool $withTime): string
    {
        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            throw new ImportRowException($column->label.' must be a date.');
        }

        return $withTime ? $date->toDateTimeString() : $date->toDateString();
    }

    private function castJson(ImportColumnDefinition $column, string $value): mixed
    {
        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new ImportRowException($column->label.' must be a JSON object.');
        }

        return $decoded;
    }

    private function castEnum(ImportColumnDefinition $column, string $value): BackedEnum|UnitEnum
    {
        $enum = $column->enumClass;

        if (! is_string($enum) || ! enum_exists($enum)) {
            throw new ImportRowException($column->label.' is not a valid choice.');
        }

        if (is_subclass_of($enum, BackedEnum::class)) {
            $match = $enum::tryFrom($value) ?? (ctype_digit($value) ? $enum::tryFrom((int) $value) : null);

            if ($match instanceof BackedEnum) {
                return $match;
            }
        }

        foreach ($enum::cases() as $case) {
            if (strcasecmp($case->name, $value) === 0) {
                return $case;
            }

            if ($case instanceof BackedEnum && strcasecmp((string) $case->value, $value) === 0) {
                return $case;
            }
        }

        throw new ImportRowException($column->label.' "'.$value.'" is not a valid choice.');
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;

        return (string) str($header)->lower()->squish()->replace([' ', '-'], '_');
    }
}
