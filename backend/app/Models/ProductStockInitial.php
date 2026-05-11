<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock y promedio unitario inicial por producto y almacén (registro de apertura).
 *
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int|null $unit_id
 * @property int $stock
 * @property string|null $price_unit_avg
 */
class ProductStockInitial extends Model
{
    protected $table = 'product_stock_initials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'unit_id',
        'stock',
        'price_unit_avg',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'price_unit_avg' => 'decimal:4',
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
