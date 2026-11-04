<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\ItemMaster;
use App\Models\SecondaryOcrSource;
use App\Models\SecondarySalesLine;
use App\Models\SecondarySalesStatement;
use App\Models\SkuUnitConversion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SecondarySalesService
{
    /**
     * Create a new class instance.
     */
    public const HEADERS = ['invoice_number', 'invoice_date', 'batch_no', 'sku_code', 'unit', 'quantity', 'gross_value', 'discount_value', 'transaction_type', 'physical_reference'];

    public function ingest(User $user, UploadedFile $file, array $data): SecondarySalesStatement
    {
        abort_unless($user->can('Create:SecondarySales'), 403);
        $stockist = AccountMaster::query()->applyVisibility('AccountMaster')->where('accept_secondary_statements', true)->findOrFail($data['stockist_id']);
        abort_unless($user->can('view', $stockist), 403);
        abort_unless((int) $stockist->territory_id === (int) $data['territory_id'], 422, 'Select the stockist territory from its master.');
        $reviewer = User::query()->findOrFail($data['reviewer_id']);
        abort_unless($reviewer->id !== $user->id && $reviewer->can('Review:SecondarySales'), 422, 'Select an authorized RSM or Commercial Excellence reviewer other than yourself.');
        $hash = hash_file('sha256', $file->getRealPath());
        $rows = $this->readRows($file);
        $path = null;
        try {
            return DB::transaction(function () use ($user, $stockist, $reviewer, $file, $data, $hash, $rows, &$path): SecondarySalesStatement {
                $stockist->newQuery()->whereKey($stockist->id)->lockForUpdate()->firstOrFail();
                $existing = SecondarySalesStatement::query()->where('stockist_id', $stockist->id)->where('source_sha256', $hash)->where('period', $data['period'])->where('status', '!=', 'rejected')->first();
                if ($existing) {
                    abort_unless((int) $existing->created_by === (int) $user->id || (int) $existing->reviewer_id === (int) $user->id, 409, 'This statement was already uploaded. Ask the assigned reviewer to reconcile it.');
                    $this->linkOcrSource($user, $existing, $data);

                    return $existing->load('lines');
                }
                $path = Storage::disk('local')->putFile('secondary-statements', $file);
                abort_unless(is_string($path), 500, 'Statement source could not be stored.');
                $statement = SecondarySalesStatement::query()->create(['stockist_id' => $stockist->id,
                    'territory_id' => $data['territory_id'], 'created_by' => $user->id, 'reviewer_id' => $reviewer->id,
                    'period' => $data['period'], 'status' => 'pending_review', 'source_path' => $path, 'source_sha256' => $hash]);
                $seen = [];
                $needsReconciliation = false;
                foreach ($rows as $index => $raw) {
                    $line = $this->normalize($stockist->id, $data['period'], $raw);
                    $identity = $line['group_key'].'|'.$line['item_master_id'];
                    if (isset($seen[$identity])) {
                        $line['errors'][] = 'duplicate_sku_line';
                    }
                    $seen[$identity] = true;
                    if (SecondarySalesLine::query()->where('group_key', $line['group_key'])->where('secondary_sales_statement_id', '!=', $statement->id)
                        ->whereHas('statement', fn ($query) => $query->where('status', '!=', 'rejected'))->exists()) {
                        $line['errors'][] = 'duplicate_submission';
                    }
                    if ($line['errors']) {
                        $needsReconciliation = true;
                    }
                    $statement->lines()->create([...$line, 'raw' => $raw, 'row_number' => $index + 2]);
                }
                if ($needsReconciliation) {
                    $statement->update(['status' => 'reconciliation']);
                }
                $this->linkOcrSource($user, $statement, $data);

                return $statement->load('lines');
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    private function linkOcrSource(User $user, SecondarySalesStatement $statement, array $data): void
    {
        if (empty($data['ocr_source_id'])) {
            return;
        }
        $source = SecondaryOcrSource::query()->where('created_by', $user->id)->lockForUpdate()->findOrFail($data['ocr_source_id']);
        abort_unless((int) $source->stockist_id === (int) $statement->stockist_id
            && (int) $source->territory_id === (int) $statement->territory_id
            && (int) $source->reviewer_id === (int) $statement->reviewer_id && $source->period === $statement->period,
            422, 'The mapped statement must use the OCR source stockist, territory, month and reviewer.');
        $history = $source->mapping_history ?? [];
        if ($source->secondary_sales_statement_id && (int) $source->secondary_sales_statement_id !== (int) $statement->id) {
            $previous = SecondarySalesStatement::query()->findOrFail($source->secondary_sales_statement_id);
            abort_unless($previous->status === 'rejected', 409, 'Review or reject the previous mapped statement before replacing its mapping.');
            $history[] = ['statement_id' => $previous->id, 'mapping_note' => $source->mapping_note, 'replaced_at' => now()->toIso8601String(), 'replaced_by' => $user->id];
        }
        abort_unless(trim((string) ($data['ocr_mapping_note'] ?? '')) !== '', 422, 'Record how the extracted rows were checked and mapped.');
        $source->update(['secondary_sales_statement_id' => $statement->id, 'mapping_note' => $data['ocr_mapping_note'], 'mapping_history' => $history, 'status' => 'converted']);
    }

    private function readRows(UploadedFile $file): array
    {
        $reader = strtolower($file->getClientOriginalExtension()) === 'xlsx'
            ? new Reader : new \OpenSpout\Reader\CSV\Reader;
        $rows = [];
        try {
            if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
                $zip = new \ZipArchive;
                abort_unless($zip->open($file->getRealPath()) === true, 422, 'Invalid Excel file.');
                $expanded = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $expanded += $zip->statIndex($i)['size'];
                }
                $zip->close();
                abort_if($expanded > 50 * 1024 * 1024, 422, 'Excel file expands beyond 50 MB.');
            }
            $reader->open($file->getRealPath());
            $sheetCount = 0;
            foreach ($reader->getSheetIterator() as $sheet) {
                abort_if(++$sheetCount > 1, 422, 'Use one statement sheet per upload.');
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value), $row->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($value) => strtolower(ltrim($value, "\xEF\xBB\xBF")), $values);
                        abort_unless($header === self::HEADERS, 422, 'Use the standardized secondary-sales column order from the template.');

                        continue;
                    }
                    abort_if(count($rows) >= 5000, 422, 'Limit each statement to 5000 rows.');
                    abort_if(count($values) > count(self::HEADERS), 422, 'Every row must follow the template columns.');
                    $values = array_pad($values, count(self::HEADERS), '');
                    $rows[] = array_combine(self::HEADERS, $values);
                }
            }
        } catch (ValidationException $error) {
            throw $error;
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['file' => 'The statement could not be read. Use the CSV/Excel template.']);
        } finally {
            $reader->close();
        }
        abort_if($rows === [], 422, 'The statement has no transaction rows.');

        return $rows;
    }

    private function normalize(int $stockistId, string $period, array $raw): array
    {
        $raw['invoice_number'] = mb_strtoupper(trim($raw['invoice_number']));
        $raw['batch_no'] = mb_strtoupper(trim($raw['batch_no']));
        $raw['physical_reference'] = mb_strtoupper(trim($raw['physical_reference']));
        $errors = [];
        foreach (['invoice_number', 'batch_no', 'sku_code'] as $field) {
            if ($raw[$field] === '' || mb_strlen($raw[$field]) > 100) {
                $errors[] = 'invalid_'.$field;
            }
        }
        $date = null;
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $raw['invoice_date']);
            if ($parsed->format('Y-m-d') === $raw['invoice_date'] && $parsed->format('Y-m') === $period) {
                $date = $parsed->toDateString();
            }
        } catch (\Throwable) {
        }
        if ($date === null) {
            $errors[] = 'invalid_invoice_date_or_period';
        }
        $item = ItemMaster::query()->where('item_code', $raw['sku_code'])->first();
        if (! $item) {
            $errors[] = 'unknown_sku';
        }
        $unit = strtolower($raw['unit']);
        $conversion = $item ? SkuUnitConversion::query()->where('item_master_id', $item->id)->where('unit', $unit)->first() : null;
        if (! $conversion || (float) $conversion->base_units <= 0) {
            $errors[] = 'missing_unit_conversion';
        }
        $quantity = preg_match('/^\d{1,9}(\.\d{1,6})?$/', $raw['quantity']) ? (float) $raw['quantity'] : 0;
        if ($quantity <= 0) {
            $errors[] = 'invalid_quantity';
        }
        $gross = $this->paise($raw['gross_value']);
        $discount = $this->paise($raw['discount_value']);
        if ($gross === null || $discount === null || $discount > $gross) {
            $errors[] = 'invalid_value_or_discount';
        }
        $type = strtolower($raw['transaction_type']);
        if (! in_array($type, ['sale', 'return', 'breakage_expiry'], true)) {
            $errors[] = 'invalid_transaction_type';
        }
        if ($type !== 'sale' && ($raw['physical_reference'] === '' || mb_strlen($raw['physical_reference']) > 100 || $discount !== 0)) {
            $errors[] = 'adjustment_requires_physical_reference_and_zero_discount';
        }
        $factor = (float) ($conversion?->base_units ?? 0);
        $base = round($quantity * $factor, 6);
        if ($base > 999999999999) {
            $errors[] = 'quantity_overflow';
            $base = 0;
        }

        return ['invoice_number' => mb_substr($raw['invoice_number'], 0, 100), 'invoice_date' => $date,
            'batch_no' => mb_substr($raw['batch_no'], 0, 100), 'item_master_id' => $item?->id,
            'unit' => mb_substr($unit, 0, 30), 'quantity' => $quantity, 'conversion_factor' => $factor, 'base_quantity' => $base,
            'gross_paise' => $gross ?? 0, 'discount_paise' => $discount ?? 0, 'transaction_type' => mb_substr($type, 0, 30),
            'physical_reference' => mb_substr($raw['physical_reference'], 0, 100),
            'group_key' => hash('sha256', json_encode([$stockistId, $raw['invoice_number'], $raw['batch_no']])),
            'adjustment_key' => $type === 'sale' ? null : hash('sha256', json_encode([$stockistId, $item?->id, $raw['batch_no'], $raw['physical_reference']])),
            'errors' => $errors];
    }

    private function paise(string $value): ?int
    {
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function review(User $user, SecondarySalesStatement $statement, string $decision, string $note, array $verifiedIds = []): SecondarySalesStatement
    {
        return DB::transaction(function () use ($user, $statement, $decision, $note, $verifiedIds): SecondarySalesStatement {
            AccountMaster::query()->whereKey($statement->stockist_id)->lockForUpdate()->firstOrFail();
            $statement = SecondarySalesStatement::query()->lockForUpdate()->findOrFail($statement->id);
            abort_unless($user->can('Review:SecondarySales') && (int) $statement->reviewer_id === (int) $user->id && (int) $statement->created_by !== (int) $user->id, 403);
            abort_if(in_array($statement->status, ['approved', 'rejected'], true), 409, 'This statement has already been reviewed.');
            if ($decision === 'approved') {
                $lines = $statement->lines()->get();
                foreach ($lines as $line) {
                    abort_if(array_diff($line->errors, ['duplicate_submission']), 422, 'Correct invalid source rows and upload a new statement before approval.');
                    if ($line->transaction_type !== 'sale') {
                        abort_unless(in_array($line->id, $verifiedIds, true), 422, 'Explicitly confirm physical verification of every return/breakage line.');
                        abort_if(DB::table('secondary_sales_adjustments')->where('adjustment_key', $line->adjustment_key)->exists(), 409, 'This physical adjustment has already been deducted.');
                        DB::table('secondary_sales_adjustments')->insert(['adjustment_key' => $line->adjustment_key, 'secondary_sales_line_id' => $line->id]);
                        $line->update(['physically_verified' => true]);
                    }
                }
                foreach ($lines->pluck('group_key')->unique() as $key) {
                    abort_if(DB::table('secondary_sales_groups')->where('group_key', $key)->exists(), 409, 'An approved invoice/batch group already exists. Reconcile this duplicate without replacing approved data.');
                    DB::table('secondary_sales_groups')->insert(['group_key' => $key, 'secondary_sales_statement_id' => $statement->id]);
                }
            }
            $statement->update(['status' => $decision, 'review_note' => $note, 'reviewed_at' => now()]);

            return $statement->load('lines');
        });
    }

    public function summary(int $territoryId, string $from, string $to): array
    {
        return $this->ledgerValues($this->aggregate($this->ledgerQuery($territoryId, $from, $to))->first());
    }

    private function ledgerQuery(int $territoryId, string $from, string $to): Builder
    {
        return DB::table('secondary_sales_lines as line')->join('secondary_sales_statements as statement', 'statement.id', '=', 'line.secondary_sales_statement_id')
            ->where('statement.status', 'approved')->where('statement.territory_id', $territoryId)->whereBetween('line.invoice_date', [$from, $to]);
    }

    private function aggregate(Builder $query): Builder
    {
        return $query->selectRaw("COALESCE(SUM(CASE WHEN line.transaction_type = 'sale' THEN line.gross_paise ELSE 0 END), 0) as gross_paise,
            COALESCE(SUM(CASE WHEN line.transaction_type = 'sale' THEN line.discount_paise ELSE 0 END), 0) as discount_paise,
            COALESCE(SUM(CASE WHEN line.transaction_type = 'return' AND line.physically_verified = 1 THEN line.gross_paise ELSE 0 END), 0) as returns_paise,
            COALESCE(SUM(CASE WHEN line.transaction_type = 'breakage_expiry' AND line.physically_verified = 1 THEN line.gross_paise ELSE 0 END), 0) as breakage_expiry_paise,
            COALESCE(SUM(CASE WHEN line.transaction_type = 'sale' THEN line.base_quantity WHEN line.physically_verified = 1 THEN -line.base_quantity ELSE 0 END), 0) as net_base_quantity");
    }

    private function ledgerValues(object $row): array
    {
        $values = [];
        foreach (['gross_paise', 'discount_paise', 'returns_paise', 'breakage_expiry_paise'] as $key) {
            $values[$key] = (int) $row->$key;
        }

        return ['basis' => 'verified_secondary_sales', ...$values, 'net_paise' => $values['gross_paise'] - $values['discount_paise'] - $values['returns_paise'] - $values['breakage_expiry_paise'],
            'net_base_quantity' => round((float) $row->net_base_quantity, 6)];
    }

    public function breakdowns(int $territoryId, string $from, string $to): array
    {
        $stockists = $this->aggregate($this->ledgerQuery($territoryId, $from, $to)->leftJoin('account_masters as account', 'account.id', '=', 'statement.stockist_id')
            ->select('statement.stockist_id as id', 'account.name')->groupBy('statement.stockist_id', 'account.name'))->get();
        $products = $this->aggregate($this->ledgerQuery($territoryId, $from, $to)->leftJoin('item_masters as item', 'item.id', '=', 'line.item_master_id')
            ->select('line.item_master_id as id', 'item.item_name as name')->groupBy('line.item_master_id', 'item.item_name'))->get();
        $map = fn ($rows) => $rows->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, ...$this->ledgerValues($row)])->values()->all();

        return ['stockists' => $map($stockists), 'products' => $map($products)];
    }
}
