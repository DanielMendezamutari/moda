<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryKardexEntry extends Model
{
    protected $table = 'inventory_kardex_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'unit_id',
        'occurred_at',
        'movement_type',
        'detail_label',
        'reference_type',
        'reference_id',
        'quantity_delta',
        'unit_cost',
        'total_value_delta',
        'in_qty',
        'in_unit_value',
        'in_total_value',
        'out_qty',
        'out_unit_value',
        'out_total_value',
        'balance_quantity',
        'balance_avg_cost',
        'balance_total_value',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'quantity_delta' => 'integer',
            'unit_cost' => 'decimal:4',
            'total_value_delta' => 'decimal:4',
            'in_qty' => 'decimal:4',
            'in_unit_value' => 'decimal:4',
            'in_total_value' => 'decimal:4',
            'out_qty' => 'decimal:4',
            'out_unit_value' => 'decimal:4',
            'out_total_value' => 'decimal:4',
            'balance_quantity' => 'integer',
            'balance_avg_cost' => 'decimal:4',
            'balance_total_value' => 'decimal:4',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
