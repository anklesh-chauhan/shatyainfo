<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListFlexiblePlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['start' => ['sometimes', 'date_format:Y-m-d'], 'user_id' => ['sometimes', 'integer', 'min:1'], 'include_team' => ['sometimes', 'boolean']];
    }

    public function messages(): array
    {
        return [
            'start.date_format' => 'Choose a valid week start date.',
            'include_team.boolean' => 'Choose whether to include the team review filters.',
        ];
    }
}
