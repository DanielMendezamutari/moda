<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'address',
        'branch_id',
        'state',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsToMany<Product, $this, ProductWarehouse>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_warehouses')
            ->using(ProductWarehouse::class)
            ->withPivot(['id', 'unit_id', 'stock', 'umbral'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<ProductStockInitial, $this>
     */
    public function productStockInitials(): HasMany
    {
        return $this->hasMany(ProductStockInitial::class);
    }
}
