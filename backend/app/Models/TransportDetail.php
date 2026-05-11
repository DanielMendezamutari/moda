<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportDetail extends Model
{
    public const STATE_SOLICITUD = 'solicitud';

    public const STATE_SALIDA = 'salida';

    public const STATE_ENTREGA = 'entrega';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'transport_id',
        'product_id',
        'unit_id',
        'quantity',
        'price_unit',
        'line_total',
        'state',
        'description',
        'user_salida_id',
        'date_salida',
        'user_entrega_id',
        'date_entrega',
        'inventory_departed_at',
        'inventory_arrived_at',
        'transfer_unit_cost',
        'transfer_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'price_unit' => 'decimal:2',
            'line_total' => 'decimal:2',
            'date_salida' => 'datetime',
            'date_entrega' => 'datetime',
            'inventory_departed_at' => 'datetime',
            'inventory_arrived_at' => 'datetime',
            'transfer_unit_cost' => 'decimal:4',
            'transfer_value' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Transport, $this>
     */
    public function transport(): BelongsTo
    {
        return $this->belongsTo(Transport::class);
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
    public function userSalida(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_salida_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userEntrega(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_entrega_id');
    }
}
