<?php

namespace App\Models;

use App\Services\Travel\SfcDistanceService;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class StandardFareChart extends BaseModel
{
    use HasApprovalWorkflow, HasFactory, SoftDeletes;

    protected $fillable = [
        'from_area_town_id',
        'to_area_town_id',
        'distance_km',
        'distance_source',
        'fare_amount',
        'territory_id',
        'is_active',
        'patch_id',
        'type_master_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'distance_km' => 'decimal:2',
        'fare_amount' => 'decimal:2',
    ];

    public static function normalizeCityPair(int $fromAreaTownId, int $toAreaTownId): array
    {
        return [
            min($fromAreaTownId, $toAreaTownId),
            max($fromAreaTownId, $toAreaTownId),
        ];
    }

    public function fromAreaTown(): BelongsTo
    {
        return $this->belongsTo(CityPinCode::class, 'from_area_town_id');
    }

    public function toAreaTown(): BelongsTo
    {
        return $this->belongsTo(CityPinCode::class, 'to_area_town_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class, 'patch_id');
    }

    public function typeMaster()
    {
        return $this->belongsTo(TypeMaster::class, 'type_master_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $chart): void {
            if ($chart->from_area_town_id === null || $chart->to_area_town_id === null) {
                return;
            }

            [$cityAId, $cityBId] = self::normalizeCityPair((int) $chart->from_area_town_id, (int) $chart->to_area_town_id);
            $chart->from_area_town_id = $cityAId;
            $chart->to_area_town_id = $cityBId;

            $duplicateExists = self::query()
                ->whereKeyNot($chart->id)
                ->where('from_area_town_id', $chart->from_area_town_id)
                ->where('to_area_town_id', $chart->to_area_town_id)
                ->where('is_active', (bool) $chart->is_active)
                ->where(function ($query) use ($chart) {
                    if ($chart->territory_id === null) {
                        $query->whereNull('territory_id');

                        return;
                    }

                    $query->where('territory_id', $chart->territory_id);
                })
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'from_area_town_id' => 'Duplicate SFC route exists for this city pair and territory scope.',
                ]);
            }
        });

        static::created(function (self $chart): void {
            if ((float) ($chart->distance_km ?? 0) > 0) {
                return;
            }

            app(SfcDistanceService::class)->populateChartDistance($chart);
        });
    }
}
