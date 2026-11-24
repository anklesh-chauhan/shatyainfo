<?php

namespace App\Models;

use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LocationMaster extends BaseModel
{
    use HasApprovalWorkflow, HasFactory;

    protected $fillable = [
        'name',
        'location_code',
        'description',
        'is_active',
        'latitude',
        'longitude',
        'image',
        'typeable_id',
        'typeable_type',
        'parent_id',
    ];

    public function getDocumentNumber(): string
    {
        return (string) $this->location_code;
    }

    public function territories(): BelongsToMany
    {
        return $this->belongsToMany(Territory::class, 'location_territory', 'location_master_id', 'territory_id')
            ->withTimestamps();
    }

    public function scopeApplyTerritoryVisibility(Builder $query, array $territoryIds): Builder
    {
        return $query->whereHas('territories', fn (Builder $territories) => $territories->whereKey($territoryIds));
    }

    public function typeable()
    {
        return $this->morphTo();
    }

    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function contactDetail()
    {
        return $this->morphOne(ContactDetail::class, 'contactable');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('typeable');
    }

    public function parentLocation()
    {
        return $this->belongsTo(LocationMaster::class, 'parent_id');
    }

    public function subLocations()
    {
        return $this->hasMany(LocationMaster::class, 'parent_id');
    }

    public function items()
    {
        return $this->belongsToMany(ItemMaster::class, 'item_location')
            ->withPivot('quantity') // Include quantity in the pivot table
            ->withTimestamps();
    }

    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'location_master_id');
    }

    public function goodsReceiptNotes()
    {
        return $this->hasMany(GoodsReceiptNote::class, 'location_master_id');
    }

    public function employmentDetails()
    {
        return $this->hasMany(EmploymentDetail::class, 'work_location_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($location_master) {
            $location_master->location_code = NumberSeries::getNextNumber(LocationMaster::class);
        });

        static::created(function ($location_master) {
            NumberSeries::incrementNextNumber(LocationMaster::class);
        });

        static::saving(function ($model) {
            if ($model->typeable_id && ! $model->typeable_type) {
                $model->typeable_type = TypeMaster::class;
            }
        });
    }
}
