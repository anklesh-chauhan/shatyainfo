<?php

namespace App\Models;

use App\Services\AccountActivationService;
use App\Services\AccountAssignmentService;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountMaster extends BaseModel
{
    use HasApprovalWorkflow, HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'type_master_id',
        'name',
        'external_reference',
        'account_code',
        'phone_number',
        'email',
        'secondary_email',
        'website',
        'no_of_employees',
        'twitter',
        'linked_in',
        'annual_revenue',
        'sic_code',
        'ticker_symbol',
        'description',
        'industry_type_id',
        'region_id',
        'territory_id',
        'ref_dealer_contact',
        'commission',
        'alias',
        'parent_id',
        'rating_type_id',
        'account_status_id',
        'specialty_id',
        'therapy_area_id',
        'institution_account_id',
        'supplier_account_id',
        'account_ownership_id',
        'approval_status',
    ];

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function customerPrices()
    {
        return $this->hasMany(CustomerPrice::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function typeMaster()
    {
        return $this->belongsTo(TypeMaster::class, 'type_master_id');
    }

    public function parentType(): ?TypeMaster
    {
        return $this->typeMaster?->parent;
    }

    public function industryType(): BelongsTo
    {
        return $this->belongsTo(IndustryType::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function dealerName()
    {
        return $this->belongsTo(ContactDetail::class, 'ref_dealer_contact');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(AccountMaster::class, 'parent_id');
    }

    public function ratingType(): BelongsTo
    {
        return $this->belongsTo(RatingType::class);
    }

    public function accountStatus(): BelongsTo
    {
        return $this->belongsTo(AccountStatus::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(AccountReportingMaster::class, 'specialty_id');
    }

    public function therapyArea(): BelongsTo
    {
        return $this->belongsTo(AccountReportingMaster::class, 'therapy_area_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'institution_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supplier_account_id');
    }

    public function divisionProfiles(): HasMany
    {
        return $this->hasMany(AccountDivisionProfile::class);
    }

    public function callTargets(): HasMany
    {
        return $this->hasMany(AccountCallTarget::class);
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(AccountAssignmentHistory::class);
    }

    public function visitables(): MorphMany
    {
        return $this->morphMany(VisitableVisit::class, 'visitable');
    }

    /**
     * All visits related to this account
     */
    public function visits()
    {
        return Visit::whereHas('visitables', function ($query) {
            $query->where('visitable_type', self::class)
                ->where('visitable_id', $this->id);
        });
    }

    public function bankDetail()
    {
        return $this->morphMany(BankDetail::class, 'bankable');
    }

    public function creditDetail()
    {
        return $this->hasOne(AccountMasterCreditDetail::class);
    }

    public function gstDetail()
    {
        return $this->hasOne(AccountMasterGSTDetail::class, 'account_master_id', 'id');
    }

    public function statutoryDetail()
    {
        return $this->hasOne(AccountMasterStatutoryDetail::class, 'account_master_id', 'id');
    }

    public function accountOwnership(): BelongsTo
    {
        return $this->belongsTo(AccountOwnership::class);
    }

    public function contactDetails(): BelongsToMany
    {
        return $this->belongsToMany(ContactDetail::class, 'account_master_contact_details');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'contactDetails.designation', // 👈 REQUIRED
            ]);
    }

    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function category(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getParentTypeAttribute(): ?TypeMaster
    {
        if (! $this->typeMaster) {
            return null;
        }

        return $this->typeMaster->parent ?: $this->typeMaster;
    }

    public function patches(): BelongsToMany
    {
        return $this->belongsToMany(Patch::class)
            ->using(AccountMasterPatch::class)
            ->withPivot(['sequence_no', 'distance_km'])
            ->withTimestamps();
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            if ($this->exists) {
                self::withTrashed()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            }

            return parent::save($options);
        });
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function (self $account): void {
            app(AccountActivationService::class)->validateReferences($account);
            app(AccountActivationService::class)->validateActivation($account);
        });
        static::saved(function (self $account): void {
            if ($account->wasRecentlyCreated || $account->wasChanged(['owner_id', 'territory_id'])) {
                app(AccountAssignmentService::class)->capture($account);
            }
        });

        static::creating(function ($accountMaster) {
            $accountMaster->account_code = null;
        });

        static::created(function ($accountMaster) {
            $base = 'ACC-'.str_pad((string) $accountMaster->id, 8, '0', STR_PAD_LEFT);
            $code = $base;
            $suffix = 1;
            while (self::withTrashed()->where('account_code', $code)->exists()) {
                $code = $base.'-'.$suffix++;
            }
            $accountMaster->forceFill(['account_code' => $code])->saveQuietly();

            if ($accountMaster->typeMaster?->typeable_type === self::class && $accountMaster->typeMaster?->creates_company) {
                $company = Company::create([
                    'name' => $accountMaster->name,
                    'phone_number' => $accountMaster->phone_number,
                    'email' => $accountMaster->email,
                    'secondary_email' => $accountMaster->secondary_email,
                    'no_of_employees' => $accountMaster->no_of_employees,
                    'industry_type_id' => $accountMaster->industry_type_id,
                    'twitter' => $accountMaster->twitter,
                    'linked_in' => $accountMaster->linked_in,
                    'website' => $accountMaster->website,
                    'description' => $accountMaster->description,
                    'account_master_id' => $accountMaster->id, // establish link
                ]);
            }
        });

        static::deleting(function ($account_master) {
            // belongsToMany → detach
            $account_master->contactDetails()->detach();

            // morphMany → delete or soft delete
            $account_master->addresses()->each(function ($address) {
                $address->delete(); // soft delete
            });
        });
        static::restoring(function ($account_master) {
            $account_master->addresses()->restore();
            $account_master->contactDetails()->restore();
        });
        static::forceDeleted(function ($account_master) {
            $account_master->addresses()->forceDelete();
            $account_master->contactDetails()->forceDelete();
        });
        static::updated(function ($account_master) {
            // $account_master->addresses()->sync($account_master->addresses()->pluck('addresses.id')->toArray());
            $account_master->contactDetails()->sync($account_master->contactDetails()->pluck('contact_details.id')->toArray());
        });
        static::restored(function ($account_master) {
            $account_master->addresses()->restore();
            $account_master->contactDetails()->restore();
        });
    }
}
