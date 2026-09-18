<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SalesDcrExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalesDcrExpense
 */
class SalesDcrExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_dcr_id' => $this->sales_dcr_id,
            'expense_type_id' => $this->expense_type_id,
            'transport_mode_id' => $this->transport_mode_id,
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'rate' => $this->rate !== null ? (float) $this->rate : null,
            'is_auto_calculated' => (bool) $this->is_auto_calculated,
            'remarks' => $this->remarks,
            'meta' => $this->meta,
            'expense_type' => $this->whenLoaded('expenseType', fn (): ?array => $this->expenseType ? [
                'id' => $this->expenseType->id,
                'name' => $this->expenseType->name,
                'code' => $this->expenseType->code,
            ] : null),
        ];
    }
}
