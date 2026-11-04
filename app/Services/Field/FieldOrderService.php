<?php

namespace App\Services\Field;

use App\Helpers\SalesDocumentQuickCreate;
use App\Models\FieldOrderState;
use App\Models\ItemMaster;
use App\Models\SalesDocumentItem;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;

class FieldOrderService
{
    public function apply(User $user, Visit $visit, array $payload, string $eventId, CarbonImmutable $capturedAt, bool $reviewedReplacement = false): FieldOrderState
    {
        $state = FieldOrderState::query()->where('order_id', $payload['order_id'])->lockForUpdate()->first();
        if (! $state) {
            abort_unless($visit->primaryCompany()?->contactDetails()->exists(), 422, 'Add a customer contact before booking an order.');
            $order = SalesDocumentQuickCreate::createFromVisit($visit, SalesOrder::class);
            $state = FieldOrderState::query()->create(['order_id' => $payload['order_id'], 'user_id' => $user->id,
                'visit_id' => $visit->id, 'sales_order_id' => $order->id,
                'fields' => ['description' => $order->description, 'delivery_date' => null], 'clocks' => []]);
        }
        abort_unless((int) $state->user_id === (int) $user->id && (int) $state->visit_id === (int) $visit->id, 404, 'Order not found for this call.');
        $order = SalesOrder::query()->lockForUpdate()->findOrFail($state->sales_order_id);
        $this->assertEditable($order);
        [$fields, $clocks] = $this->currentFields($state, $order);
        foreach ($payload['changes'] as $change) {
            $field = $change['field'];
            $time = CarbonImmutable::parse($change['updated_at'])->utc();
            abort_if($time->greaterThan($capturedAt) || $time->lessThan($capturedAt->startOfDay()), 422, 'Order edit timestamps must fall within the captured call day.');
            $old = $clocks[$field] ?? null;
            if (! $reviewedReplacement && $old && ($time->lessThan(CarbonImmutable::parse($old['updated_at']))
                || ($time->equalTo(CarbonImmutable::parse($old['updated_at'])) && strcmp($eventId, $old['event_id']) <= 0))) {
                continue;
            }
            $value = $change['value'];
            if (str_starts_with($field, 'line:')) {
                abort_unless(is_numeric($value) && is_finite((float) $value) && (float) $value >= 0, 422, 'Order quantities must be non-negative numbers.');
                $value = round((float) $value, 3);
                abort_if($value > 999999999, 422, 'Order quantity exceeds the supported limit.');
                if ($value > 0) {
                    ItemMaster::query()->findOrFail((int) substr($field, 5));
                }
            } elseif ($field === 'description') {
                abort_unless(is_string($value) && mb_strlen($value) <= 2000, 422, 'Keep the order description within 2000 characters.');
            } elseif ($field === 'delivery_date') {
                abort_unless($value === null || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                    && CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value), 422, 'Choose a valid delivery date.');
            } else {
                abort(422, 'Unsupported order field.');
            }
            $fields[$field] = $value;
            $clocks[$field] = ['updated_at' => $time->toIso8601String(), 'event_id' => $eventId];
        }
        abort_unless(collect($fields)->contains(fn ($value, $field) => str_starts_with($field, 'line:') && (float) $value > 0), 422, 'Keep at least one positive order line.');
        SalesDocumentItem::withoutEvents(function () use ($fields, $order, $user): void {
            foreach ($fields as $field => $quantity) {
                if (! str_starts_with($field, 'line:')) {
                    continue;
                }
                $itemId = (int) substr($field, 5);
                $line = $order->items()->where('item_master_id', $itemId)->first();
                if ((float) $quantity === 0.0) {
                    $line?->delete();

                    continue;
                }
                $item = ItemMaster::query()->findOrFail($itemId);
                $price = $line ? (float) $line->price : (float) ($item->selling_price ?? 0);
                $order->items()->updateOrCreate(['item_master_id' => $itemId], ['quantity' => $quantity,
                    'price' => $price, 'unit_price' => $price, 'amount' => round($quantity * $price, 2),
                    'final_taxable_amount' => round($quantity * $price, 2), 'created_by' => $line?->created_by ?? $user->id, 'updated_by' => $user->id]);
            }
        });
        $subtotal = $order->items()->get()->sum(fn ($line) => round((float) $line->quantity * (float) $line->price, 2));
        $order->forceFill(['description' => $fields['description'] ?? $order->description,
            'delivery_date' => $fields['delivery_date'] ?? null, 'gross_total' => $subtotal,
            'subtotal' => $subtotal, 'total' => $subtotal + (float) $order->tax,
            'updated_by' => $user->id])->save();
        $state->forceFill(['fields' => $fields, 'clocks' => $clocks])->save();

        return $state;
    }

    public function assertEditable(SalesOrder $order): void
    {
        $order->assertApprovalDocumentEditable();
        abort_unless($order->status === 'draft' && in_array($order->approval_status, [null, 'draft', 'rejected'], true), 409, 'Only editable order drafts can merge offline changes.');
        abort_if((float) $order->tax !== 0.0 || (float) $order->transaction_discount !== 0.0
            || (float) $order->discount_value !== 0.0 || (float) $order->round_off !== 0.0
            || $order->items()->where(fn ($query) => $query->where('discount', '>', 0)->orWhere('tax_rate', '>', 0)->orWhere('quantity_delivered', '>', 0))->exists(),
            409, 'This order has commercial adjustments or deliveries. Reconcile its changes in ERP.');
    }

    private function currentFields(FieldOrderState $state, SalesOrder $order): array
    {
        $fields = $state->fields;
        $clocks = $state->clocks;
        $current = ['description' => $order->description, 'delivery_date' => $order->delivery_date?->format('Y-m-d')];
        $times = [];
        foreach ($order->items()->get() as $line) {
            $field = 'line:'.$line->item_master_id;
            $current[$field] = (float) $line->quantity;
            $times[$field] = $line->updated_at;
        }
        foreach (array_unique([...array_keys($fields), ...array_keys($current)]) as $field) {
            $value = $current[$field] ?? (str_starts_with($field, 'line:') ? 0 : null);
            if (array_key_exists($field, $fields) && $fields[$field] == $value) {
                continue;
            }
            $fields[$field] = $value;
            $clocks[$field] = ['updated_at' => ($times[$field] ?? $order->updated_at ?? now())->toIso8601String(), 'event_id' => 'server'];
        }

        return [$fields, $clocks];
    }
}
