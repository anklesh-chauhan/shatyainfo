<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldSyncRecovery extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function resolvedVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'resolved_visit_id');
    }

    public function reviewToken(): string
    {
        return hash('sha256', json_encode([$this->id, $this->reviewer_id, $this->reason, $this->requested_at?->toIso8601String(),
            $this->resolution_mode, $this->resolution_snapshot, $this->replacement_event_id, $this->replacement_payload,
            $this->amended_at?->toIso8601String(), count($this->review_history ?? [])], JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return ['report_date' => 'immutable_date', 'captured_at' => 'immutable_datetime', 'snapshot' => 'array', 'review_history' => 'array', 'resolution_snapshot' => 'array',
            'replacement_payload' => 'array', 'amended_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'recovered_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            abort_if($record->isDirty(['user_id', 'operation_id', 'request_hash', 'path', 'report_date', 'captured_at', 'snapshot']), 409, 'The failed request evidence is permanent. Retry its original operation.');
            abort_if($record->getOriginal('recovered_at') && $record->isDirty(['resolution_mode', 'resolution_snapshot', 'resolved_visit_id', 'replacement_event_id', 'replacement_payload', 'amended_at']), 409, 'Retain the completed resolution and its server evidence.');
        });
        static::deleting(fn () => abort(409, 'Retain synchronization recovery history.'));
    }
}
