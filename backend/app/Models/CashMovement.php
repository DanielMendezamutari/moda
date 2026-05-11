<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cash_register_session_id',
        'type',
        'source',
        'amount',
        'method_payment',
        'description',
        'occurred_at',
        'sale_payment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashRegisterSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    /**
     * @return BelongsTo<SalePayment, $this>
     */
    public function salePayment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class);
    }
}
