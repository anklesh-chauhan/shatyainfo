<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\DateFormatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'date_format' => app(DateFormatService::class)->dateFormat(),
            'employee' => $this->whenLoaded('employee', fn (): ?array => $this->employee ? [
                'id' => $this->employee->id,
                'employee_id' => $this->employee->employee_id,
                'first_name' => $this->employee->first_name,
                'last_name' => $this->employee->last_name,
                'mobile_number' => $this->employee->mobile_number,
            ] : null),
        ];
    }
}
