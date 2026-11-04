<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecondaryOcrSource extends Model
{
    protected $guarded = [];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SecondarySalesStatement::class, 'secondary_sales_statement_id');
    }

    protected function casts(): array
    {
        return ['tokens' => 'array', 'mapping_history' => 'array', 'extraction_started_at' => 'immutable_datetime'];
    }

    public function canRetryExtraction(): bool
    {
        if ($this->secondary_sales_statement_id) {
            return false;
        }
        if (in_array($this->status, ['awaiting_provider', 'failed'], true)) {
            return true;
        }

        return in_array($this->status, ['queued', 'extracting'], true)
            && ($this->extraction_started_at ?? $this->updated_at)->lte(now()->subMinutes(15));
    }
}
