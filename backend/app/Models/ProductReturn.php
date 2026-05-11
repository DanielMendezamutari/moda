<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReturn extends Model
{
    public const TYPE_REPARACION = 'reparacion';

    public const TYPE_REMPLAZO = 'remplazo';

    public const TYPE_DEVOLUCION = 'devolucion';

    public const STATE_PENDIENTE = 'pendiente';

    public const STATE_REVISION = 'revision';

    public const STATE_REPARADO = 'reparado';

    public const STATE_DESCARTADO = 'descartado';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'unit_id',
        'warehouse_id',
        'quantity',
        'sale_detail_id',
        'client_id',
        'type',
        'state',
        'description',
        'user_id',
        'resolution_date',
        'description_resolution',
        'inventory_applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'resolution_date' => 'datetime',
            'inventory_applied_at' => 'datetime',
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
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<SaleDetail, $this>
     */
    public function saleDetail(): BelongsTo
    {
        return $this->belongsTo(SaleDetail::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
