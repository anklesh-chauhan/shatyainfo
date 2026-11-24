<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListVisitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'register' => ['sometimes', 'boolean'],
            'scope' => ['sometimes', 'in:personal,team'],
            'trash' => ['sometimes', 'in:active,with,only'],
            'approval_status' => ['sometimes', 'in:draft,pending,approved,rejected'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'scope.in' => 'Select My visits or Team visits.',
            'trash.in' => 'Select active visits, trash or all records.',
            'search.max' => 'Use at most 100 characters to search by document number.',
        ];
    }
}
