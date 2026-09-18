<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TypeMaster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TypeMaster
 */
class AccountTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'description' => $this->description,
        ];
    }
}
