<?php

namespace App\Models;

use App\Enums\ItemType;
use App\Traits\HasApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemMaster extends BaseModel
{
    use HasApprovalWorkflow, HasFactory, SoftDeletes;

    protected $fillable = [
        'variant_name',
        'parent_id',
        'sku',
        'item_code',
        'item_name',
        'item_type',
        'description',
        'category_id',
        'category_type', // For polymorphic relationship
        'item_brand_id',
        'purchase_price',
        'hsn_code',
        'selling_price',
        'tax_rate',
        'discount',
        'opening_stock',
        'minimum_stock_level',
        'reorder_quantity',
        'unit_of_measurement_id',
        'lead_time',
        'storage_location',
        'barcode',
        'expiry_date',
        'packaging_type_id',
        'per_packing_qty',
        'has_variants',
    ];

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
        ];
    }

    public function customerPrices()
    {
        return $this->hasMany(CustomerPrice::class);
    }

    public function parent()
    {
        return $this->belongsTo(ItemMaster::class, 'parent_id');
    }

    public function variants()
    {
        return $this->hasMany(ItemMaster::class, 'parent_id');
    }

    public function category()
    {
        return $this->morphTo();
    }

    public function brand()
    {
        return $this->belongsTo(ItemBrand::class, 'item_brand_id');
    }

    public function unitOfMeasurement()
    {
        return $this->belongsTo(UnitOfMeasurement::class);
    }

    public function suppliers()
    {
        return $this->belongsToMany(AccountMaster::class, 'item_master_account_masters')
            ->withTimestamps();
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'model');
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'model');
    }

    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class, 'packaging_type_id');
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'item_master_lead', 'item_master_id', 'lead_id')
            ->withPivot(['quantity', 'price'])->withTimestamps();
    }

    public function locations()
    {
        return $this->belongsToMany(LocationMaster::class, 'item_location')
            ->withPivot('quantity') // Include quantity in the pivot table
            ->withTimestamps();
    }

    public function inventoryStocks()
    {
        return $this->hasMany(InventoryStock::class, 'item_master_id');
    }

    public function inventoryMovements()
    {
        return $this->hasMany(InventoryMovement::class, 'item_master_id');
    }

    public function purchaseOrderLines()
    {
        return $this->hasMany(PurchaseOrderLine::class, 'item_master_id');
    }

    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'item_master_tax_pivots')
            ->withTimestamps();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item_master) {
            $item_master->item_code = NumberSeries::getNextNumber(ItemMaster::class);
        });

        static::created(function ($item_master) {
            NumberSeries::incrementNextNumber(ItemMaster::class);
        });

        static::saving(function ($item) {
            if ($item->category_id && ! $item->category_type) {
                $item->category_type = Category::class;
            }
        });
    }
}
