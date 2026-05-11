<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductWarehouse extends Pivot
{
    protected $table = 'product_warehouses';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'unit_id',
        'stock',
        'umbral',
        'sale_price',
        'purchase_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'umbral' => 'integer',
            'sale_price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
