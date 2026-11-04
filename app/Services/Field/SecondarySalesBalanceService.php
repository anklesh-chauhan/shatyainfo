<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\ItemMaster;
use App\Models\SalesInvoice;
use App\Models\SecondarySalesBalance;
use App\Models\SecondarySalesPreference;
use App\Models\SkuUnitConversion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SecondarySalesBalanceService
{
    public const HEADERS = [
        'stockist_code', 'period_month_year', 'product_code', 'batch_number', 'expiry_date', 'pts_price',
        'opening_qty', 'purchase_qty', 'primary_free_qty', 'purchase_return_qty', 'secondary_sales_qty',
        'secondary_free_qty', 'sales_return_qty', 'expiry_breakage_qty', 'reported_closing_qty',
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return SecondarySalesPreference::current()->snapshot();
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $raw
     * @return array{balance: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function normalize(AccountMaster $stockist, string $period, array $raw, array $rules, ?int $replacesStatementId): array
    {
        $errors = [];
        $stockistCode = mb_strtoupper(trim($raw['stockist_code']));
        if ($stockistCode === '' || $stockistCode !== mb_strtoupper(trim((string) $stockist->account_code))) {
            $errors[] = 'invalid_stockist_code';
        }
        $rowPeriod = $this->yearMonth($raw['period_month_year']);
        if ($rowPeriod === null || $rowPeriod !== $period) {
            $errors[] = 'invalid_period';
        }
        $batch = mb_strtoupper(trim($raw['batch_number']));
        $product = trim($raw['product_code']);
        if ($product === '' || mb_strlen($product) > 100) {
            $errors[] = 'invalid_product_code';
        }
        if ($batch === '' || mb_strlen($batch) > 100) {
            $errors[] = 'invalid_batch_number';
        }
        $item = $product === '' ? null : ItemMaster::query()->where('item_code', $product)->first();
        if (! $item) {
            $errors[] = 'unknown_sku';
        }
        $unit = strtolower((string) $rules['quantity_unit']);
        $conversion = $item ? SkuUnitConversion::query()->where('item_master_id', $item->id)->where('unit', $unit)->first() : null;
        if (! $conversion || (float) $conversion->base_units <= 0) {
            $errors[] = 'missing_unit_conversion';
        }
        $pts = $this->paise($raw['pts_price']);
        if ($pts === null) {
            $errors[] = 'invalid_pts_price';
        }
        $quantities = [];
        foreach ([...SecondarySalesPreference::QUANTITY_FIELDS, 'reported_closing_qty'] as $field) {
            $quantities[$field] = $this->quantity($raw[$field], (bool) $rules['allow_fractional_quantities']);
            if ($quantities[$field] === null) {
                $errors[] = 'invalid_'.$field;
                $quantities[$field] = 0.0;
            }
        }
        $expiry = $this->expiryDate($raw['expiry_date']);
        if (trim($raw['expiry_date']) !== '' && $expiry === null) {
            $errors[] = 'invalid_expiry_date';
        }
        $calculated = 0.0;
        foreach ($rules['formula_signs'] as $field => $sign) {
            $calculated += $quantities[$field] * (int) $sign;
        }
        $calculated = round($calculated, 6);
        $variance = round($quantities['reported_closing_qty'] - $calculated, 6);
        $tolerance = max((float) $rules['variance_tolerance_qty'], abs($calculated) * ((float) $rules['variance_tolerance_percent'] / 100));
        $matched = abs($variance) <= $tolerance;
        if (! $matched) {
            $errors[] = 'stock_balance_variance';
        }
        if ($rules['flag_expired_batches'] && $expiry && $rowPeriod && $quantities['reported_closing_qty'] > 0 && $expiry->format('Y-m') <= $rowPeriod) {
            $errors[] = 'expired_batch_still_in_stock';
        }
        $prior = $this->priorClosing($stockist->id, $item?->id, $batch, $period, (string) $rules['prior_closing_source'], $replacesStatementId);
        if ($prior === null) {
            if ($rules['require_prior_closing']) {
                $errors[] = 'missing_prior_closing';
            }
        } elseif (abs($quantities['opening_qty'] - $prior) > 0.0000001) {
            $errors[] = 'opening_does_not_match_prior_closing';
        }
        $invoiced = null;
        if ($rules['verify_primary_purchases'] && $item) {
            $invoiced = $this->primaryInvoicedQty($stockist->id, $item->id, $period);
            if (abs($quantities['purchase_qty'] - $invoiced) > (float) $rules['primary_purchase_tolerance_qty']) {
                $errors[] = 'primary_purchase_mismatch';
            }
        }
        $factor = (float) ($conversion?->base_units ?? 0);
        $groupKey = hash('sha256', json_encode(['STOCK', $stockist->id, $period, $item?->id, $batch]));
        $reference = 'STOCK-'.$period.'-'.mb_substr($product, 0, 40).'-'.mb_substr($batch, 0, 40);
        $invoiceDate = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();
        $invoiceNumber = mb_substr('BAL-'.$period.'-'.$product.'-'.$batch, 0, 100);
        $scheme = $rules['include_secondary_free_in_scheme_deduction']
            ? min(($pts ?? 0) * $quantities['secondary_sales_qty'], ($pts ?? 0) * $quantities['secondary_free_qty'])
            : 0;
        $lines = [];
        foreach ([
            ['sale', $quantities['secondary_sales_qty'], ($pts ?? 0) * $quantities['secondary_sales_qty'], $scheme, null],
            ['return', $quantities['sales_return_qty'], ($pts ?? 0) * $quantities['sales_return_qty'], 0, $reference.'-RET'],
            ['breakage_expiry', $quantities['expiry_breakage_qty'], ($pts ?? 0) * $quantities['expiry_breakage_qty'], 0, $reference.'-EXP'],
        ] as [$type, $quantity, $gross, $discount, $physical]) {
            if ($quantity <= 0 && $type !== 'sale') {
                continue;
            }
            if ($quantity <= 0 && $type === 'sale' && $scheme <= 0) {
                continue;
            }
            $base = round($quantity * $factor, 6);
            $lines[] = [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'batch_no' => mb_substr($batch, 0, 100),
                'item_master_id' => $item?->id,
                'unit' => mb_substr($unit, 0, 30),
                'quantity' => $quantity,
                'conversion_factor' => $factor,
                'base_quantity' => $base,
                'gross_paise' => (int) round($gross),
                'discount_paise' => (int) round($discount),
                'transaction_type' => $type,
                'physical_reference' => $physical ? mb_substr($physical, 0, 100) : '',
                'group_key' => $groupKey,
                'adjustment_key' => $type === 'sale' ? null : hash('sha256', json_encode(['STOCK', $stockist->id, $item?->id, $batch, $period, $type])),
                'errors' => [],
            ];
        }

        return [
            'balance' => [
                'item_master_id' => $item?->id,
                'batch_no' => mb_substr($batch, 0, 100),
                'expiry_date' => $expiry?->toDateString(),
                'pts_paise' => $pts ?? 0,
                ...$quantities,
                'calculated_closing_qty' => $calculated,
                'variance_qty' => $variance,
                'prior_closing_qty' => $prior,
                'primary_invoiced_qty' => $invoiced,
                'status' => $matched ? 'matched' : 'discrepancy',
                'group_key' => $groupKey,
                'errors' => array_values(array_unique($errors)),
            ],
            'lines' => $lines,
        ];
    }

    private function yearMonth(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            $value = substr($value, 0, 7);
        }
        foreach (['!Y-m', '!m-Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
                if ($parsed->format($format === '!Y-m' ? 'Y-m' : 'm-Y') === $value) {
                    return $parsed->format('Y-m');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function expiryDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $month = $this->yearMonth($value);
        if ($month) {
            return CarbonImmutable::createFromFormat('!Y-m', $month);
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed->format('Y-m-d') === $value ? $parsed : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function quantity(string $value, bool $fractional): ?float
    {
        $value = trim($value);
        if ($fractional) {
            if (! preg_match('/^\d{1,9}(\.\d{1,6})?$/', $value)) {
                return null;
            }

            return (float) $value;
        }
        if (! preg_match('/^\d{1,9}(\.0+)?$/', $value)) {
            return null;
        }

        return (float) (int) $value;
    }

    private function paise(string $value): ?int
    {
        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', trim($value))) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', trim($value)), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function priorClosing(int $stockistId, ?int $itemId, string $batch, string $period, string $source, ?int $replacesStatementId): ?float
    {
        if ($itemId === null || $batch === '') {
            return null;
        }
        $priorPeriod = CarbonImmutable::createFromFormat('!Y-m', $period)->subMonth()->format('Y-m');
        $row = SecondarySalesBalance::query()->where('item_master_id', $itemId)->where('batch_no', $batch)
            ->whereHas('statement', function ($query) use ($stockistId, $priorPeriod, $replacesStatementId): void {
                $query->where('stockist_id', $stockistId)->where('period', $priorPeriod)->where('status', 'approved')
                    ->when($replacesStatementId, fn ($inner) => $inner->where('id', '!=', $replacesStatementId));
            })->first();
        if (! $row) {
            return null;
        }

        return round((float) ($source === 'calculated' ? $row->calculated_closing_qty : $row->reported_closing_qty), 6);
    }

    private function primaryInvoicedQty(int $stockistId, int $itemId, string $period): float
    {
        $from = $period.'-01';
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();

        return round((float) DB::table('sales_document_items as item')
            ->join('sales_invoices as invoice', function ($join): void {
                $join->on('invoice.id', '=', 'item.document_id')->where('item.document_type', SalesInvoice::class);
            })
            ->where('invoice.account_master_id', $stockistId)->where('item.item_master_id', $itemId)
            ->where('invoice.approval_status', 'approved')->whereIn('invoice.status', ['sent', 'accepted'])
            ->whereBetween('invoice.date', [$from, $to])->whereNull('item.deleted_at')->sum('item.quantity'), 6);
    }
}
