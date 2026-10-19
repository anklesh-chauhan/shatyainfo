<?php

namespace App\Models;

use App\Services\DataImport\FieldTravelImport;
use App\Services\Travel\PatchStandardFareChartService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AccountMasterPatch extends Pivot
{
    protected $table = 'account_master_patch';

    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'patch_id',
        'account_master_id',
        'sequence_no',
        'distance_km',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
        'distance_km' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }

    public function accountMaster(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes (Reusable Query Logic)
    |--------------------------------------------------------------------------
    */

    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence_no');
    }

    public function scopeForPatch($query, int $patchId)
    {
        return $query->where('patch_id', $patchId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers (Business Logic Ready)
    |--------------------------------------------------------------------------
    */

    public function isFirst(): bool
    {
        return $this->sequence_no === 1;
    }

    public function isLast(): bool
    {
        $max = self::where('patch_id', $this->patch_id)->max('sequence_no');

        return $this->sequence_no === $max;
    }

    /*
    |--------------------------------------------------------------------------
    | Boot (Domain Rules)
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            // Auto-assign sequence if not provided
            if (is_null($model->sequence_no)) {
                $model->sequence_no = self::where('patch_id', $model->patch_id)
                    ->max('sequence_no') + 1;
            }
        });

        static::created(function (self $model): void {
            if (FieldTravelImport::isRunning()) {
                return;
            }
            $patch = $model->patch;

            if ($patch === null) {
                return;
            }

            app(PatchStandardFareChartService::class)->ensureForPatch($patch);
        });
    }
}
