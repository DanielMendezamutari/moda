<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transport extends Model
{
    public const STATE_SOLICITUD = 'solicitud';

    public const STATE_REVISION_SALIDA = 'revision_salida';

    public const STATE_SALIDA = 'salida';

    public const STATE_LLEGADA = 'llegada';

    public const STATE_REVISION_LLEGADA = 'revision_llegada';

    public const STATE_ENTREGA = 'entrega';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'warehouse_start_id',
        'warehouse_end_id',
        'date_emision',
        'user_id',
        'state',
        'importe',
        'igv',
        'total',
        'description',
        'date_entrega',
        'reference',
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
    public function warehouseStart(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_start_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouseEnd(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_end_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TransportDetail, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(TransportDetail::class);
    }
}
