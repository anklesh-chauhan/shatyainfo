<?php

namespace App\Models;

use App\Enums\SampleRequestStatus;
use Database\Factories\SampleRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SampleRequest extends BaseModel
{
    /** @use HasFactory<SampleRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'document_number',
        'employee_id',
        'territory_id',
        'location_master_id',
        'request_date',
        'status',
        'purpose',
        'campaign_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'status' => SampleRequestStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'location_master_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SampleRequestLine::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SampleIssue::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function isEditable(): bool
    {
        return ! $this->trashed() && $this->status === SampleRequestStatus::Draft;
    }

    public function scopeApplyOwnVisibility(Builder $query, mixed $user): Builder
    {
        return $query->whereHas('employee', fn (Builder $employee) => $employee->where('login_id', $user->id));
    }

    public function refreshIssueStatus(): void
    {
        $this->load('lines');

        $status = match (true) {
            ! $this->lines->contains(fn (SampleRequestLine $line): bool => (float) $line->quantity_issued > 0) => SampleRequestStatus::Approved,
            $this->lines->contains(fn (SampleRequestLine $line): bool => $line->remainingApprovedQuantity() > 0) => SampleRequestStatus::PartiallyIssued,
            default => SampleRequestStatus::Fulfilled,
        };

        $this->forceFill(['status' => $status])->save();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SampleRequest $sampleRequest): void {
            $sampleRequest->document_number ??= NumberSeries::getNextNumber(SampleRequest::class);
            $sampleRequest->status ??= SampleRequestStatus::Draft;
        });

        static::created(function (): void {
            NumberSeries::incrementNextNumber(SampleRequest::class);
        });
    }
}
