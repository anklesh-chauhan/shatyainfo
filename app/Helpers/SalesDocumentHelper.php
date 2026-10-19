<?php

namespace App\Helpers;

use App\Models\NumberSeries;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SalesDocumentHelper
{
    /**
     * Create a new sales document (Sales Order, Sales Invoice, etc.) from a Quote or other source.
     *
     * @param  Model  $source  The source document (e.g., Quote)
     * @param  string  $targetClass  The target model class (e.g., \App\Models\SalesOrder::class)
     * @param  array  $extraData  Optional overrides for target fields
     * @return Model Newly created document instance
     */
    public static function createFrom(Model $source, string $targetClass, array $extraData = []): Model
    {
        return DB::transaction(function () use ($source, $targetClass, $extraData) {
            $source = $source->newQuery()->lockForUpdate()->findOrFail($source->getKey());
            if (method_exists($source, 'assertApprovalComplete')) {
                $source->assertApprovalComplete();
            }

            // ✅ 1. Build base data
            $data = array_merge([
                'document_number' => NumberSeries::getNextNumber($targetClass),
                'date' => now(),
                'lead_id' => $source->lead_id ?? null,
                'sales_person_id' => $source->sales_person_id ?? null,
                'contact_detail_id' => $source->contact_detail_id ?? null,
                'account_master_id' => $source->account_master_id ?? null,
                'billing_address_id' => $source->billing_address_id ?? null,
                'shipping_address_id' => $source->shipping_address_id ?? null,
                'currency' => $source->currency ?? 'INR',
                'payment_term_id' => $source->payment_term_id ?? null,
                'payment_method_id' => $source->payment_method_id ?? null,
                'description' => $source->description ?? null,
                'shipping_method_id' => $source->shipping_method_id ?? null,
                'shipping_cost' => $source->shipping_cost ?? 0,
                'packing_forwarding' => $source->packing_forwarding ?? 0,
                'insurance_charges' => $source->insurance_charges ?? 0,
                'other_charges' => $source->other_charges ?? 0,
                'discount_mode' => $source->discount_mode ?? null,
                'discount_type' => $source->discount_type ?? null,
                'discount_value' => $source->discount_value ?? 0,
                'transaction_discount' => $source->transaction_discount ?? 0,
                'gross_total' => $source->gross_total ?? 0,
                'subtotal' => $source->subtotal ?? 0,
                'tax' => $source->tax ?? 0,
                'round_off' => $source->round_off ?? 0,
                'total' => $source->total ?? 0,
                'status' => 'draft',
            ], $extraData);

            // ✅ 2. Create the target document
            /** @var Model $target */
            $target = $targetClass::create($data);

            // ✅ 3. Copy items if available
            if (method_exists($source, 'items') && $source->items->count()) {
                foreach ($source->items as $item) {
                    $target->items()->create([
                        'item_master_id' => $item->item_master_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'discount' => $item->discount,
                        'tax' => $item->tax,
                        'total' => $item->total,
                        'description' => $item->description,
                        'unit' => $item->unit,
                        'unit_price' => $item->unit_price,
                        'hsn_sac' => $item->hsn_sac,
                        'tax_rate' => $item->tax_rate,
                        'amount' => $item->amount,
                        'final_taxable_amount' => $item->final_taxable_amount,
                    ]);
                }
            }

            if (method_exists($source, 'termsAndCondition') && $source->termsAndCondition) {
                $target->termsAndCondition()->create([
                    'terms_type_id' => $source->termsAndCondition->terms_type_id,
                    'terms_and_conditions' => $source->termsAndCondition->terms_and_conditions,
                    'content' => $source->termsAndCondition->content ?? null,
                ]);
            }

            $targetBaseName = class_basename($targetClass);

            if (method_exists($source, 'salesOrders') && $targetBaseName === 'SalesOrder') {
                $source->salesOrders()->syncWithoutDetaching([$target->id]);
                if (method_exists($target, 'quotes')) {
                    $target->quotes()->syncWithoutDetaching([$source->id]);
                }
            }

            if (method_exists($source, 'salesInvoices') && $targetBaseName === 'SalesInvoice') {
                $source->salesInvoices()->syncWithoutDetaching([$target->id]);
                if ($source instanceof Quote && method_exists($target, 'quotes')) {
                    $target->quotes()->syncWithoutDetaching([$source->id]);
                }
            }

            return $target;
        });
    }
}
