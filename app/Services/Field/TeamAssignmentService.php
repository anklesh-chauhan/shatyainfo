<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\Employee;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompanyLaunchService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\SpreadsheetFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class TeamAssignmentService
{
    public const MAPPING_COLUMNS = ['customer_code', 'customer_name', 'current_owner_email', 'owner_email'];

    public function authorize(): void
    {
        app(CompanyOnboardingService::class)->authorize();
        abort_unless(FieldTeamOnboardingService::canManage(auth()->user()), 403);
    }

    public function previewHqs(array $employeeIds, int $hqId, bool $replaceExisting = false): array
    {
        $this->authorize();
        Validator::make(['members' => $employeeIds, 'hq' => $hqId], [
            'members' => ['required', 'array', 'min:1', 'max:500'],
            'members.*' => ['required', 'integer', 'distinct'],
            'hq' => ['required', 'integer'],
        ])->validate();
        $hq = LocationMaster::query()->where('is_active', true)->find($hqId);
        if (! $hq) {
            throw ValidationException::withMessages(['bulkHqId' => 'Select an active HQ.']);
        }
        $employees = Employee::query()->with('user')->whereKey($employeeIds)->orderBy('id')->get();
        if ($employees->count() !== count($employeeIds)) {
            throw ValidationException::withMessages(['launchMembers' => 'Select employees available in this company.']);
        }
        $current = FieldUserHq::query()->with('location')->whereIn('user_id', $employees->pluck('login_id'))->get()->keyBy('user_id');
        $plans = [];
        $errors = [];
        foreach ($employees as $employee) {
            $existing = $current->get($employee->login_id);
            $change = ! $existing || ($replaceExisting && $existing->location_master_id !== $hqId);
            $error = null;
            if (! $employee->is_active || $employee->is_deleted || ! $employee->user) {
                $error = 'An active employee with a linked login is required.';
            } elseif ($change && ! app(FieldTeamOnboardingService::class)->availableHqs($employee->login_id)->whereKey($hqId)->exists()) {
                $error = 'This HQ must share a territory with one of this MR’s positions.';
            }
            if ($error) {
                $errors[] = $employee->full_name.': '.$error;
            }
            $plans[] = ['employee_id' => $employee->id, 'user_id' => $employee->login_id, 'name' => $employee->full_name,
                'current_id' => $existing?->location_master_id, 'current' => $existing?->location?->name,
                'hq_id' => $hqId, 'hq' => $hq->name, 'change' => $change,
                'action' => $error ? 'Needs attention' : ($change ? ($existing ? 'Replace' : 'Assign') : 'Keep existing')];
        }

        return ['plans' => $plans, 'errors' => $errors, 'fingerprint' => $this->fingerprint($plans)];
    }

    public function assignHqs(array $employeeIds, int $hqId, bool $replaceExisting, string $fingerprint): int
    {
        $this->authorize();

        return auth()->user()->getConnection()->transaction(function () use ($employeeIds, $hqId, $replaceExisting, $fingerprint): int {
            $this->lockOrganization();
            $employees = Employee::query()->whereKey($employeeIds)->orderBy('id')->lockForUpdate()->get();
            User::query()->whereKey($employees->pluck('login_id'))->orderBy('id')->lockForUpdate()->get();
            $preview = $this->previewHqs($employeeIds, $hqId, $replaceExisting);
            $this->assertReviewed($preview, $fingerprint);
            $count = 0;
            foreach ($preview['plans'] as $plan) {
                if ($plan['change']) {
                    app(FieldTeamOnboardingService::class)->save(auth()->user(), [
                        'user_id' => $plan['user_id'], 'location_master_id' => $hqId, 'account_ids' => [], 'calls_per_month' => 2,
                    ]);
                    $count++;
                }
            }

            return $count;
        });
    }

    public function readMappings(string $path, bool $workbook): array
    {
        $this->authorize();
        $temporary = $workbook ? app(SpreadsheetFile::class)->toCsv($path, 'Customer mappings') : null;
        $stream = fopen($temporary ?? $path, 'r');
        try {
            $headers = array_map(fn ($value): string => trim(ltrim((string) $value, "\xEF\xBB\xBF")), fgetcsv($stream, escape: '') ?: []);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['customer_code', 'owner_email'], $headers) || array_diff($headers, self::MAPPING_COLUMNS)) {
                throw ValidationException::withMessages(['mappingFile' => 'Use the customer mapping template with customer_code and owner_email columns.']);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                $line++;
                if (collect($values)->every(fn ($value): bool => blank($value))) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw ValidationException::withMessages(['mappingFile' => 'Row '.$line.': column count does not match the header.']);
                }
                $data = array_combine($headers, array_map(fn ($value): string => trim((string) $value), $values));
                $rows[] = ['line' => $line, 'customer_code' => $data['customer_code'], 'owner_email' => $data['owner_email']];
                if (count($rows) > 500) {
                    throw ValidationException::withMessages(['mappingFile' => 'Upload up to 500 customers at a time. Remove other rows from this upload.']);
                }
            }

            return $rows;
        } finally {
            fclose($stream);
            if ($temporary) {
                unlink($temporary);
            }
        }
    }

    public function previewMappings(array $rows, bool $allowTransfers = false): array
    {
        $this->authorize();
        Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.line' => ['required', 'integer'],
            'rows.*.customer_code' => ['required', 'string', 'max:255'],
            'rows.*.owner_email' => ['present', 'nullable', 'string', 'max:255'],
        ])->validate();
        $plans = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $row) {
            $accounts = AccountMaster::query()->applyVisibility('AccountMaster')->with('owner')
                ->where(fn ($query) => $query->where('account_code', $row['customer_code'])->orWhere('external_reference', $row['customer_code']))->limit(2)->get();
            $account = $accounts->count() === 1 ? $accounts->first() : null;
            if (! $account || ! auth()->user()->can('update', $account)) {
                $errors[] = 'Row '.$row['line'].': customer code must identify one customer you can update.';

                continue;
            }
            if (isset($seen[$account->id])) {
                $errors[] = 'Row '.$row['line'].': duplicate customer. Include each customer once.';

                continue;
            }
            $seen[$account->id] = true;
            $owner = null;
            if (filled($row['owner_email'])) {
                $owners = User::query()->where('email', $row['owner_email'])
                    ->whereHas('employee', fn ($query) => $query->where('is_active', true))->limit(2)->get();
                if ($owners->count() !== 1) {
                    $errors[] = 'Row '.$row['line'].': owner_email must identify one active employee login.';

                    continue;
                }
                $owner = $owners->first();
            }
            $change = $owner && $owner->id !== $account->owner_id;
            $transfer = $change && $account->owner_id !== null;
            if ($transfer && ! $allowTransfers) {
                $errors[] = 'Row '.$row['line'].': this customer already has an owner. Enable customer transfers and preview again to change it.';
            }
            $plans[] = ['line' => $row['line'], 'account_id' => $account->id, 'code' => $row['customer_code'], 'name' => $account->name,
                'current_id' => $account->owner_id, 'current' => $account->owner?->email,
                'owner_id' => $owner?->id ?? $account->owner_id, 'owner' => $owner?->email ?? $account->owner?->email,
                'change' => (bool) $change, 'action' => $change ? ($transfer ? 'Transfer' : 'Assign') : 'Keep existing'];
        }

        return ['plans' => $plans, 'errors' => $errors, 'fingerprint' => $this->fingerprint($plans)];
    }

    public function importMappings(array $rows, bool $allowTransfers, string $fingerprint): int
    {
        $this->authorize();

        return auth()->user()->getConnection()->transaction(function () use ($rows, $allowTransfers, $fingerprint): int {
            $this->lockOrganization();
            $codes = array_column($rows, 'customer_code');
            AccountMaster::query()->applyVisibility('AccountMaster')
                ->where(fn ($query) => $query->whereIn('account_code', $codes)->orWhereIn('external_reference', $codes))
                ->orderBy('id')->lockForUpdate()->get();
            $preview = $this->previewMappings($rows, $allowTransfers);
            $this->assertReviewed($preview, $fingerprint);
            $count = 0;
            foreach ($preview['plans'] as $plan) {
                if ($plan['change']) {
                    AccountMaster::query()->findOrFail($plan['account_id'])->update(['owner_id' => $plan['owner_id']]);
                    $count++;
                }
            }

            return $count;
        });
    }

    public function writeMappingTemplate(string $path): void
    {
        $this->authorize();
        $writer = new Writer;
        $writer->openToFile($path);
        $text = (new Style)->setFormat('@')->setShouldWrapText();
        try {
            $writer->getCurrentSheet()->setName('Customer mappings')->setColumnWidthForRange(32, 1, 4);
            $writer->addRow(Row::fromValues(self::MAPPING_COLUMNS, (new Style)->setFontBold()));
            foreach (AccountMaster::query()->applyVisibility('AccountMaster')->with('owner')->orderBy('id')->lazy() as $account) {
                if (auth()->user()->can('update', $account)) {
                    $writer->addRow(Row::fromValues([$account->account_code ?: $account->external_reference, $account->name, $account->owner?->email, ''], $text));
                }
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Instructions')->setColumnWidth(110, 1);
            foreach ([
                'Fill owner_email in Customer mappings using a login from Representatives. Blank keeps the current owner. Remove rows you do not want to upload. Maximum 500 rows and 2 MB per upload.',
                'customer_code accepts an existing account_code or external_reference. Duplicate or ambiguous customers are rejected. customer_name and current_owner_email are for reference only.',
                'Preview before confirming. Existing owners are preserved unless you explicitly enable customer transfers. Confirmation saves the whole file or nothing. Customer details, territories, patches and call targets are preserved.',
                'All three working methods use the same ownership mapping. Direct reporting does not require HQs. Flexible planning requires active HQs before launch. Monthly planning additionally requires territory-linked HQs, positions and patches; ownership mapping does not create these links.',
            ] as $instruction) {
                $writer->addRow(Row::fromValues([$instruction], $text));
            }
            $writer->addNewSheetAndMakeItCurrent()->setName('Representatives')->setColumnWidthForRange(35, 1, 2);
            $writer->addRow(Row::fromValues(['owner_email', 'Representative']));
            foreach (User::query()->whereHas('employee', fn ($query) => $query->where('is_active', true))->orderBy('name')->lazy() as $user) {
                $writer->addRow(Row::fromValues([$user->email, $user->name], $text));
            }
        } finally {
            $writer->close();
        }
    }

    private function fingerprint(array $plans): string
    {
        $organization = app(CompanyOnboardingService::class)->organization();
        $revision = app(CompanyLaunchService::class)->proposal($organization) ?? app(FieldPlanningService::class)->active();

        return hash('sha256', json_encode([Tenant::current()?->id, auth()->id(), $organization?->id, $revision?->id, $revision?->version, $plans], JSON_THROW_ON_ERROR));
    }

    private function assertReviewed(array $preview, string $fingerprint): void
    {
        if ($preview['errors'] !== []) {
            throw ValidationException::withMessages(['assignments' => $preview['errors']]);
        }
        if (! hash_equals($preview['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages(['assignments' => 'Assignments or the working method changed. Preview again before confirming.']);
        }
    }

    private function lockOrganization(): void
    {
        $organization = app(CompanyOnboardingService::class)->organization();
        if ($organization) {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        }
    }
}
