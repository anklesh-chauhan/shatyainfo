<?php

namespace App\Services\Field;

use App\Models\CustomerPrice;
use App\Models\ItemMaster;
use App\Models\Organization;
use App\Models\SalesDocument;
use App\Models\SalesDocumentItem;
use Illuminate\Validation\ValidationException;

class SalesDocumentPricing
{
    public function apply(SalesDocument $document, array $input, bool $keepPrices): void
    {
        $existing = $document->items()->get()->keyBy('id');
        $itemIds = array_column($input, 'item_master_id');
        $catalog = ItemMaster::query()->with(['taxes.components', 'unitOfMeasurement'])->whereKey($itemIds)->get()->keyBy('id');
        $prices = CustomerPrice::query()->where('customer_id', $document->account_master_id)
            ->whereIn('item_master_id', $itemIds)->orderBy('id')->get()->groupBy('item_master_id');
        $lineDiscounts = in_array($document->discount_mode, ['line_item', 'both'], true);
        $transactionDiscounts = in_array($document->discount_mode, ['transaction', 'both'], true);

        $lines = [];
        $gross = 0;
        $net = 0;
        foreach ($input as $index => $line) {
            $current = isset($line['id']) ? $existing->get($line['id']) : null;
            if (isset($line['id']) && $current === null) {
                throw ValidationException::withMessages(["items.$index.id" => 'Select an item line on this document.']);
            }
            $item = $catalog->get($line['item_master_id']);
            if (! $item) {
                throw ValidationException::withMessages(["items.$index.item_master_id" => 'Select an available item.']);
            }
            $saved = $keepPrices && $current && (int) $current->item_master_id === $item->id ? $current : null;
            $customerPrice = $prices->get($item->id)?->first();
            $quantity = (float) $line['quantity'];
            $price = (float) ($saved?->unit_price ?? $customerPrice?->price ?? $item->selling_price ?? 0);
            $discount = (float) ($saved?->discount ?? $customerPrice?->discount ?? $item->discount ?? 0);
            abort_if($price < 0 || $discount < 0 || $discount > 100 || $quantity * $price >= 10 ** 13, 422,
                'An item has pricing outside the supported range.');
            $lineGross = (int) round($quantity * $price * 100);
            $lineNet = $lineGross - ($lineDiscounts ? (int) round($lineGross * $discount / 100) : 0);
            $gross += $lineGross;
            $net += $lineNet;
            $lines[] = [
                'record' => $current,
                'item' => $item,
                'net' => $lineNet,
                'data' => [
                    'item_master_id' => $item->id,
                    'quantity' => $quantity,
                    'unit' => $saved?->unit ?? $item->unitOfMeasurement?->abbreviation,
                    'hsn_sac' => $saved?->hsn_sac ?? $item->hsn_code,
                    'description' => array_key_exists('description', $line) ? $line['description'] : ($saved?->description ?? $item->description),
                    'unit_price' => $price,
                    'discount' => $discount,
                    'price' => $lineGross / 100,
                    'amount' => $lineNet / 100,
                ],
            ];
        }
        abort_if($gross >= 10 ** 15, 422, 'The document total is outside the supported range.');

        $discountValue = (float) $document->discount_value;
        $discount = 0;
        if ($transactionDiscounts) {
            $percentage = $document->discount_type !== 'amount';
            abort_if($percentage && $discountValue > 100, 422, 'The document discount cannot exceed 100%.');
            $discount = (int) round($percentage ? $net * $discountValue / 100 : $discountValue * 100);
            abort_if($discount > $net, 422, 'The document discount exceeds the item subtotal.');
        }

        $local = $this->isLocal($document);
        $allocated = 0;
        $remainingNet = $net;
        $taxDetails = [];
        $taxTotal = 0;
        $retained = [];
        foreach ($lines as $line) {
            $share = $remainingNet > 0 ? (int) round(($discount - $allocated) * $line['net'] / $remainingNet) : 0;
            $allocated += $share;
            $remainingNet -= $line['net'];
            $taxable = $line['net'] - $share;
            $lineRate = 0;
            foreach ($line['item']->taxes as $tax) {
                foreach ($tax->components->whereIn('type', $local ? ['CGST', 'SGST'] : ['IGST']) as $component) {
                    $amount = (int) round($taxable * (float) $component->rate / 100);
                    $taxTotal += $amount;
                    $lineRate += (float) $component->rate;
                    $taxDetails[] = [
                        'tax_id' => $tax->id,
                        'tax_component_id' => $component->id,
                        'type' => $component->type,
                        'rate' => $component->rate,
                        'amount' => $amount / 100,
                    ];
                }
            }
            abort_if($lineRate > 999.99, 422, 'An item has an invalid tax rate.');
            $record = $line['record'] ?? $document->items()->make();
            $record->fill([...$line['data'], 'final_taxable_amount' => $taxable / 100, 'tax_rate' => $lineRate])->save();
            $retained[] = $record->id;
        }

        $document->items()->whereNotIn('id', $retained)->delete();
        $document->taxDetails()->delete();
        $document->taxDetails()->createMany($taxDetails);
        $charges = collect(['shipping_cost', 'packing_forwarding', 'insurance_charges', 'other_charges', 'round_off'])
            ->sum(fn (string $name): int => (int) round((float) $document->$name * 100));
        $total = $net - $discount + $taxTotal + $charges;
        abort_if($total >= 10 ** 15 || $total < 0, 422, 'The document total is outside the supported range.');
        $document->forceFill([
            'gross_total' => $gross / 100,
            'subtotal' => ($net - $discount) / 100,
            'transaction_discount' => $discount / 100,
            'tax' => $taxTotal / 100,
            'total' => $total / 100,
        ])->save();
    }

    public function storedLines(SalesDocument $document): array
    {
        return $document->items()->get()->map(fn (SalesDocumentItem $line): array => [
            'id' => $line->id,
            'item_master_id' => $line->item_master_id,
            'quantity' => $line->quantity,
            'description' => $line->description,
        ])->all();
    }

    private function isLocal(SalesDocument $document): bool
    {
        $billingState = $document->billingAddress()->first()?->state_id;
        $organizationState = Organization::query()->with('addresses')->first()?->addresses->first()?->state_id;

        return $billingState !== null && $organizationState !== null && (int) $billingState === (int) $organizationState;
    }
}
