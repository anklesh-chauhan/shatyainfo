<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountReportingMaster extends Model
{
    public const KINDS = ['specialty' => 'Specialty', 'therapy_area' => 'Therapy Area', 'potential_unit' => 'Potential Unit'];

    protected $fillable = ['kind', 'code', 'name', 'is_active', 'is_monetary'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_monetary' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            Validator::make($record->getAttributes(), [
                'kind' => ['required', Rule::in(array_keys(self::KINDS))],
                'code' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:64'],
                'name' => ['required', 'string', 'max:255'],
            ])->validate();
            if ($record->exists && $record->isDirty(['kind', 'code', 'is_monetary'])) {
                throw ValidationException::withMessages(['code' => 'Kind, code and unit meaning are permanent. Create a new master for a different meaning.']);
            }
            if ($record->kind !== 'potential_unit') {
                $record->is_monetary = false;
            }
        });
    }
}
