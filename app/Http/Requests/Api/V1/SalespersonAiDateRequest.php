<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class SalespersonAiDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
        ];
    }

    public function onDate(): Carbon
    {
        $date = $this->validated('date');

        return $date ? Carbon::parse($date)->startOfDay() : today();
    }
}
