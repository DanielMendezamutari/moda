<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'unit_start_id',
        'unit_end_id',
        'user_id',
        'quantity_start',
        'quantity_end',
        'stock_delta',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_start' => 'decimal:4',
            'quantity_end' => 'decimal:4',
            'stock_delta' => 'decimal:4',
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
    public function unitStart(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_start_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unitEnd(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_end_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
