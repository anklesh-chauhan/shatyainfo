<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Field\FieldLeadService;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeadNoteRequest extends FormRequest
{
    /**
     * Notes follow lead access: a hidden lead returns 404 before validation,
     * and adding a note requires `Update:Lead`.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $lead = app(FieldLeadService::class)->findVisible($user, (int) $this->route('lead'));

        return $user->can('update', $lead);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Write a note.',
            'note.max' => 'Keep the note under 5000 characters.',
        ];
    }
}
