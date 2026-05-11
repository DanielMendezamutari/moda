<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    public const STATE_SOLICITUD = 'solicitud';

    public const STATE_ENTREGADO = 'entregado';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purchase_id',
        'product_id',
        'unit_id',
        'quantity',
        'unit_cost',
        'line_total',
        'state',
        'user_entrega_id',
        'date_entrega',
        'description',
        'inventory_received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'date_entrega' => 'datetime',
            'inventory_received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
    public function userEntrega(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_entrega_id');
    }
}
