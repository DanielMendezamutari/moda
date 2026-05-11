<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'description',
        'image_path',
        'price',
        'wholesale_price',
        'cost_price',
        'discount_percent',
        'is_gift_card',
        'is_active',
        'warranty_days',
        'category_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'is_gift_card' => 'boolean',
            'is_active' => 'boolean',
            'warranty_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Stock y umbral por almacén (tabla pivote).
     *
     * @return BelongsToMany<Warehouse, $this, ProductWarehouse>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'product_warehouses')
            ->using(ProductWarehouse::class)
            ->withPivot(['id', 'unit_id', 'stock', 'umbral', 'sale_price', 'purchase_price'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<SaleDetail, $this>
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleDetail::class);
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * @return HasMany<TransportDetail, $this>
     */
    public function transportDetails(): HasMany
    {
        return $this->hasMany(TransportDetail::class);
    }

    /**
     * @return HasMany<Conversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /**
     * Saldos y costo promedio unitario inicial por almacén (apertura).
     *
     * @return HasMany<ProductStockInitial, $this>
     */
    public function stockInitials(): HasMany
    {
        return $this->hasMany(ProductStockInitial::class);
    }
}
