<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ContactDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactDetail
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'salutation' => $this->salutation,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'mobile_number' => $this->mobile_number,
            'email' => $this->email,
            'account_master_id' => $this->account_master_id,
        ];
    }
}
