<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ExpenseType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseType
 */
class ExpenseTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
