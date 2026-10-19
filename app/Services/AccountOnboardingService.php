<?php

namespace App\Services;

use App\Models\AccountMaster;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\DataImport\AccountImportRow;
use App\Services\DataImport\ImportRowException;
use App\Services\DataImport\SpreadsheetFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountOnboardingService
{
    public const COLUMNS = [
        'external_reference', 'name', 'account_type', 'owner_email', 'status_code', 'phone_number', 'email', 'commission',
        'territory_code', 'patch_codes', 'rating', 'specialty_code', 'therapy_area_code',
        'address_type', 'street', 'city', 'area', 'pin_code', 'parent_reference', 'institution_reference', 'supplier_reference',
        'division_code', 'segment', 'profile_from', 'profile_to', 'potential_value', 'potential_unit_code', 'potential_period', 'currency', 'assessed_on',
        'calls_per_month', 'target_from', 'target_to',
    ];

    public function authorize(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        abort_unless(Tenant::current()?->accountIsConfigured() ?? true, 409);
        abort_unless(app(CompanyOnboardingService::class)->organization()?->metadata['company_setup']['organization_saved'] ?? false, 422, 'Save company information first.');
    }

    public function readFile(string $path, bool $workbook = false): array
    {
        $this->authorize();
        $temporary = null;
        try {
            if ($workbook) {
                $temporary = app(SpreadsheetFile::class)->toCsv($path, 'Accounts');
                $path = $temporary;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new ImportRowException('Cannot read the account file.');
            }
            try {
                $headers = array_map(fn ($value): string => strtolower(trim(ltrim((string) $value, "\xEF\xBB\xBF"))), fgetcsv($handle, escape: '') ?: []);
                if (count($headers) !== count(array_unique($headers)) || array_diff(['external_reference', 'name', 'account_type', 'owner_email'], $headers) || array_diff($headers, self::COLUMNS)) {
                    throw new ImportRowException('Use the Accounts template headers. Required columns: external_reference, name, account_type, owner_email.');
                }
                $rows = [];
                $line = 1;
                while (($values = fgetcsv($handle, escape: '')) !== false) {
                    $line++;
                    if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                        continue;
                    }
                    if (count($values) !== count($headers)) {
                        throw new ImportRowException('Row '.$line.' has an incorrect number of columns.');
                    }
                    $rows[] = ['line' => $line, 'data' => array_combine($headers, $values)];
                    if (count($rows) > 500) {
                        throw new ImportRowException('Import up to 500 accounts at a time.');
                    }
                }

                return $rows;
            } finally {
                fclose($handle);
            }
        } catch (ImportRowException $exception) {
            throw ValidationException::withMessages(['accountFile' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['accountFile' => 'Cannot read this file. Upload a valid CSV or XLSX workbook.']);
        } finally {
            if ($temporary !== null) {
                unlink($temporary);
            }
        }
    }

    /** A read-only plan; preview never runs model events or writes. */
    public function preview(array $input): array
    {
        $this->authorize();
        Validator::make(['rows' => $input], ['rows' => ['required', 'array', 'min:1', 'max:500']])->validate();
        $rows = [];
        foreach (array_values($input) as $index => $item) {
            $data = [];
            foreach (self::COLUMNS as $column) {
                $data[$column] = trim((string) ($item['data'][$column] ?? ''));
            }
            foreach (['external_reference', 'parent_reference', 'institution_reference', 'supplier_reference'] as $column) {
                $data[$column] = strtoupper($data[$column]);
            }
            $rows[] = ['line' => $item['line'] ?? $index + 2, 'data' => $data];
        }
        $references = array_count_values(array_column(array_column($rows, 'data'), 'external_reference'));
        $plans = [];
        $errors = [];
        foreach ($rows as $item) {
            try {
                if (($references[$item['data']['external_reference']] ?? 0) > 1) {
                    throw new ImportRowException('Duplicate external reference in this file. Use one row per account.');
                }
                $plans[] = [...$item, ...app(AccountImportRow::class)->resolve($item['data'], array_map('strval', array_keys($references)))];
            } catch (ValidationException $exception) {
                $errors[] = ['row' => $item['line'], 'reference' => $item['data']['external_reference'], 'message' => collect($exception->errors())->flatten()->implode(' ')];
            } catch (ImportRowException $exception) {
                $errors[] = ['row' => $item['line'], 'reference' => $item['data']['external_reference'], 'message' => $exception->getMessage()];
            }
        }
        $existing = AccountMaster::query()->get(['id', 'parent_id', 'external_reference']);
        $parents = $existing->mapWithKeys(fn ($account): array => ['id:'.$account->id => $account->parent_id ? 'id:'.$account->parent_id : ''])->all();
        $keys = $existing->whereNotNull('external_reference')->mapWithKeys(fn ($account): array => [strtoupper($account->external_reference) => 'id:'.$account->id])->all();
        foreach ($plans as $plan) {
            $keys[$plan['data']['external_reference']] = $plan['id'] ? 'id:'.$plan['id'] : 'ref:'.$plan['data']['external_reference'];
        }
        foreach ($plans as $plan) {
            if ($plan['data']['parent_reference'] !== '') {
                $parents[$keys[$plan['data']['external_reference']]] = $keys[$plan['data']['parent_reference']] ?? '';
            }
        }
        foreach ($plans as $plan) {
            $seen = [];
            $reference = $keys[$plan['data']['external_reference']];
            while ($reference !== '') {
                if (isset($seen[$reference])) {
                    $errors[] = ['row' => $plan['line'], 'reference' => $plan['data']['external_reference'], 'message' => 'Circular parent account hierarchy.'];
                    break;
                }
                $seen[$reference] = true;
                $reference = $parents[$reference] ?? '';
            }
        }

        return ['rows' => $rows, 'plans' => $plans, 'errors' => $errors, 'created' => count(array_filter($plans, fn ($plan): bool => $plan['id'] === null)), 'updated' => count(array_filter($plans, fn ($plan): bool => $plan['id'] !== null))];
    }

    public function import(array $rows): array
    {
        $this->authorize();

        return (new AccountMaster)->getConnection()->transaction(function () use ($rows): array {
            Organization::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $preview = $this->preview($rows);
            if ($preview['errors'] !== []) {
                throw ValidationException::withMessages(['accountFile' => collect($preview['errors'])->map(fn ($error): string => 'Row '.$error['row'].': '.$error['message'])->implode(' ')]);
            }
            $accounts = [];
            foreach ($preview['plans'] as $plan) {
                try {
                    $account = $plan['id'] ? AccountMaster::findOrFail($plan['id']) : new AccountMaster;
                    $account->fill($plan['attributes']);
                    $account->forceFill(['external_reference' => $plan['data']['external_reference']]);
                    // Apply status after required addresses and patch assignments exist.
                    $account->account_status_id = null;
                    $account->save();
                    if ($plan['address'] !== []) {
                        $account->addresses()->updateOrCreate(['is_primary' => true], $plan['address']);
                    }
                    if ($plan['patches'] !== []) {
                        $account->patches()->syncWithoutDetaching($plan['patches']);
                    }
                    foreach (['profile' => 'divisionProfiles', 'target' => 'callTargets'] as $key => $relation) {
                        if ($plan[$key] !== []) {
                            $match = ['effective_from' => $plan[$key]['effective_from']];
                            if ($key === 'profile') {
                                $match['division_ou_id'] = $plan[$key]['division_ou_id'];
                            }
                            $account->{$relation}()->updateOrCreate($match, $plan[$key]);
                        }
                    }
                    $accounts[$plan['data']['external_reference']] = $account;
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['accountFile' => 'Row '.$plan['line'].': '.collect($exception->errors())->flatten()->implode(' ')]);
                }
            }
            foreach ($preview['plans'] as $plan) {
                $account = $accounts[$plan['data']['external_reference']];
                foreach (['parent_reference' => 'parent_id', 'institution_reference' => 'institution_account_id', 'supplier_reference' => 'supplier_account_id'] as $column => $field) {
                    if ($plan['data'][$column] !== '') {
                        $account->$field = ($accounts[$plan['data'][$column]] ?? AccountMaster::where('external_reference', $plan['data'][$column])->firstOrFail())->id;
                    }
                }
                $account->account_status_id = $plan['status'];
                $account->save();
            }
            app(CompanyOnboardingService::class)->mark('accounts_reviewed');

            return ['created' => $preview['created'], 'updated' => $preview['updated']];
        });
    }
}
