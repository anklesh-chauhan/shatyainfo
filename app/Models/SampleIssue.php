<?php

namespace App\Models;

use App\Enums\SampleIssueStatus;
use App\Services\Inventory\InventoryService;
use Database\Factories\SampleIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class SampleIssue extends BaseModel
{
    /** @use HasFactory<SampleIssueFactory> */
    use HasFactory;

    protected $fillable = [
        'document_number',
        'sample_request_id',
        'from_location_id',
        'to_location_id',
        'issue_date',
        'status',
        'issued_by',
        'posted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'status' => SampleIssueStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function sampleRequest(): BelongsTo
    {
        return $this->belongsTo(SampleRequest::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(LocationMaster::class, 'to_location_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SampleIssueLine::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isEditable(): bool
    {
        return ! $this->trashed() && $this->status === SampleIssueStatus::Draft && ! $this->isPosted();
    }

    public function post(): void
    {
        app(InventoryService::class)->postSampleIssue($this, Auth::id());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (SampleIssue $sampleIssue): bool {
            return $sampleIssue->getRawOriginal('posted_at') === null;
        });

        static::deleting(fn (SampleIssue $sampleIssue): bool => $sampleIssue->status === SampleIssueStatus::Draft && ! $sampleIssue->isPosted());
        static::restoring(fn (SampleIssue $sampleIssue): bool => $sampleIssue->status === SampleIssueStatus::Draft && ! $sampleIssue->isPosted());

        static::creating(function (SampleIssue $sampleIssue): void {
            $sampleIssue->document_number ??= NumberSeries::getNextNumber(SampleIssue::class);
            $sampleIssue->status ??= SampleIssueStatus::Draft;
        });

        static::created(function (): void {
            NumberSeries::incrementNextNumber(SampleIssue::class);
        });
    }
}
