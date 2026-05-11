<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    public const STATE_SOLICITUD = 'solicitud';

    public const STATE_REVISION = 'revision';

    public const STATE_PARCIAL = 'parcial';

    public const STATE_ENTREGADO = 'entregado';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'warehouse_id',
        'user_id',
        'supplier_id',
        'date_emision',
        'state',
        'type_comprobant',
        'n_comprobant',
        'reference',
        'description',
        'notes',
        'importe',
        'igv',
        'total',
        'date_entrega',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_emision' => 'date',
            'date_entrega' => 'date',
            'importe' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
