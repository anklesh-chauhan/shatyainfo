<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends BaseModel
{
    use HasApprovalWorkflow, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'display_name',
        'logo',
        'website',
        'founded_at',
        'industry_type_id',
        'email',
        'phone',
        'fax',
        'contact_person',
        'contact_person_email',
        'contact_person_phone',
        'legal_name',
        'registration_number',
        'gst_number',
        'registration_date',
        'legal_status',
        'parent_organization_id',
        'size',
        'annual_revenue',
        'operation_hours',
        'timezone',
        'language',
        'linkedin_url',
        'twitter_url',
        'facebook_url',
        'instagram_url',
        'status',
        'created_by',
        'updated_by',
        'deleted_at',
        'metadata',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'founded_at' => 'date',
        'registration_date' => 'date',
        'annual_revenue' => 'decimal:2',
        'metadata' => 'array',
    ];

    public static function divisionsEnabled(): bool
    {
        return (bool) (static::query()->orderBy('id')->first()?->metadata['uses_divisions'] ?? true);
    }

    public function bankDetail()
    {
        return $this->morphMany(BankDetail::class, 'bankable');
    }

    /**
     * Get the addresses associated with the organization.
     */
    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function primaryAddress()
    {
        return $this->addresses()->first(); // or ->where('is_primary', true)->first() if you track primary
    }

    public function state()
    {
        return optional($this->primaryAddress())->state;
    }

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'organization_id');
    }

    public function departments()
    {
        return $this->hasMany(EmpDepartment::class, 'organization_id');
    }

    /**
     * Get the industry type associated with the organization.
     */
    public function industryType()
    {
        return $this->belongsTo(IndustryType::class, 'industry_type_id');
    }

    /**
     * Get the parent organization, if any.
     */
    public function parentOrganization()
    {
        return $this->belongsTo(Organization::class, 'parent_organization_id');
    }

    /**
     * Get the user who created the organization.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the organization.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
