<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends BaseModel
{
    use HasApprovalWorkflow, HasFactory;

    protected $fillable = ['name', 'state_id', 'country_id', 'city_class_id', 'is_hill_station'];

    protected $casts = [
        'is_hill_station' => 'boolean',
    ];

    public function scopeWhereNormalizedName(Builder $query, string $name): Builder
    {
        return $query->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))]);
    }

    public function cityClass(): BelongsTo
    {
        return $this->belongsTo(CityClass::class, 'city_class_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function pinCode()
    {
        return $this->hasOne(CityPinCode::class);
    }

    public function areaTowns()
    {
        return $this->hasMany(CityPinCode::class, 'city_id');
    }
}
