<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EDetailingPresentation extends Model
{
    protected $table = 'e_detailing_presentations';

    protected $fillable = ['visit_id', 'e_detailing_material_id', 'user_id', 'material_title', 'content_version', 'notes', 'presented_at'];

    protected function casts(): array
    {
        return ['presented_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(EDetailingMaterial::class, 'e_detailing_material_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
