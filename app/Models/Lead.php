<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use App\Traits\HasCustomerInteractionFields;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Lead extends BaseModel
{
    use HasApprovalWorkflow, HasCustomerInteractionFields, HasFactory;

    protected $fillable = [
        'owner_id',
        'reference_code',
        'transaction_date',
        'contact_detail_id',
        'company_id',
        'address_id',
        'lead_source_id',
        'rating_type_id',
        'annual_revenue',
        'territory_id',
        'description',
        'custom_fields',
        'status_id',
        'status_type',
        'account_master_id',
    ];

    public function scopeApplyOwnVisibility(Builder $query, $user): Builder
    {
        return $query->where($query->getModel()->getTable().'.owner_id', $user->id);
    }

    public function scopeApplyTerritoryVisibility(Builder $query, array $territoryIds): Builder
    {
        return $query->whereIn($query->getModel()->getTable().'.territory_id', $territoryIds);
    }

    public function convertToDeal(bool $createCompanyMaster = false, bool $createAccountMaster = false)
    {
        // Generate a new reference code for the Deal using NumberSeries
        $newReferenceCode = NumberSeries::getNextNumber(Deal::class);

        // Fetch the default status ID for deals (Negotiation/Review)
        $defaultDealStage = DealStage::where('name', 'Negotiation/Review')->first();

        // Get the company name (if available)
        $companyName = $this->accountMaster?->name ?? 'Unnamed Deal';

        // Create a new Deal instance
        $deal = Deal::create([
            'owner_id' => $this->owner_id,
            'reference_code' => $newReferenceCode,
            'deal_name' => $companyName,
            'transaction_date' => now(),
            'contact_id' => $this->contact_detail_id,
            'account_master_id' => $this->account_master_id,
            'address_id' => $this->address_id,
            'amount' => $this->annual_revenue ?? 0,
            'expected_revenue' => $this->annual_revenue ?? 0,
            'expected_close_date' => now()->addDays(30),
            'description' => $this->description,
            'status_id' => $defaultDealStage ? $defaultDealStage->id : null,
            'status_type' => DealStage::class,
        ]);

        // update an Account Master if selected
        if ($createAccountMaster && $this->account_master_id) {

            try {
                DB::beginTransaction();

                $typeMasterId = 2;

                // Generate account code for AccountMaster
                $accountCode = NumberSeries::getNextNumber(AccountMaster::class, $typeMasterId);

                // Update the AccountMaster record
                $this->accountMaster->update([
                    'type_master_id' => $typeMasterId,
                    'account_code' => $accountCode,
                ]);

                // Increment number series for AccountMaster (not Lead)
                NumberSeries::incrementNextNumber(AccountMaster::class, $typeMasterId);

                DB::commit();

            } catch (Exception $e) {
                DB::rollBack();

                throw new Exception('Failed to convert lead to customer.', 0, $e);
            }

            Notification::make()
                ->title('Account Master Created')
                ->body("Account Master for {$this->company?->name} has been created.")
                ->success()
                ->send();

        }

        // update the lead's status to "Converted"
        $convertedStatus = LeadStatus::where('name', 'Converted')->first();
        if ($convertedStatus) {
            $this->update([
                'status_id' => $convertedStatus->id,
                'status_type' => LeadStatus::class,
            ]);
        }

        return $deal;
    }

    public function territory()
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function status()
    {
        return $this->morphTo();
    }

    public function followUps()
    {
        return $this->morphMany(FollowUp::class, 'followupable');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contactDetail()
    {
        return $this->belongsTo(ContactDetail::class);
    }

    public function contactComapnyDetails()
    {
        return $this->hasMany(ContactDetail::class, 'company_id', 'company_id');
    }

    public function accountMaster()
    {
        return $this->belongsTo(AccountMaster::class, 'account_master_id');
    }

    // public function company()
    // {
    //     return $this->belongsTo(Company::class);
    // }

    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function leadSource()
    {
        return $this->belongsTo(LeadSource::class);
    }

    // public function leadStatus()
    // {
    //     return $this->belongsTo(LeadStatus::class);
    // }

    public function rating()
    {
        return $this->belongsTo(RatingType::class, 'rating_type_id');
    }

    public function customFields()
    {
        return $this->hasMany(LeadCustomField::class);
    }

    /**
     * A lead can have many notes.
     */
    public function leadNotes(): HasMany
    {
        return $this->hasMany(LeadNote::class);
    }

    public function itemMasters()
    {
        return $this->belongsToMany(ItemMaster::class)
            ->withPivot(['quantity', 'price'])
            ->withTimestamps();
    }

    public function leadActivities()
    {
        return $this->hasMany(LeadActivity::class, 'lead_id');
    }

    public function getDisplayNameAttribute()
    {
        if ($this->company) {
            return $this->company->name; // Show company name if available
        } elseif ($this->contactDetail) {
            return $this->contactDetail->full_name; // Otherwise, show contact name
        }

        return 'N/A'; // Default if neither is available
    }

    protected $casts = [
        'custom_fields' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($lead) {
            $lead->reference_code = NumberSeries::getNextNumber(Lead::class);
        });

        static::created(function ($lead) {
            NumberSeries::incrementNextNumber(Lead::class);
        });
    }
}
