<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlexiblePlanEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'version' => 'integer', 'patch_id' => 'integer', 'user_id' => 'integer', 'account_master_id' => 'integer', 'history' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'account_master_id');
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function proposal(): array
    {
        return ['client_plan_id' => $this->client_plan_id, 'account_id' => $this->account_master_id,
            'date' => $this->date->toDateString(), 'patch_id' => $this->patch_id, 'notes' => $this->notes,
            'status' => $this->status, 'expected_version' => $this->version];
    }
}
