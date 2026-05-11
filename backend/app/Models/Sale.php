<?php

namespace App\Models;

use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'cash_register_session_id',
        'client_id',
        'type_client',
        'reference',
        'subtotal',
        'igv',
        'total',
        'state_sale',
        'state_payment',
        'debt',
        'paid_out',
        'date_validation',
        'date_pay_complete',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'debt' => 'decimal:2',
            'paid_out' => 'decimal:2',
            'date_validation' => 'datetime',
            'date_pay_complete' => 'datetime',
        ];
    }

    public function applyPaymentState(): void
    {
        $t = (float) $this->total;
        $p = (float) $this->paid_out;
        $this->debt = max(0, round($t - $p, 2));
        if ($this->debt <= 0.01) {
            $this->state_payment = 'paid';
            $this->date_pay_complete = now();
        } elseif ($p > 0.01) {
            $this->state_payment = 'partial';
            $this->date_pay_complete = null;
        } else {
            $this->state_payment = 'pending';
            $this->date_pay_complete = null;
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Líneas del documento (tabla `sale_details`).
     *
     * @return HasMany<SaleDetail, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleDetail::class);
    }

    /**
     * @return HasMany<SalePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /**
     * @return BelongsTo<CashRegisterSession, $this>
     */
    public function cashRegisterSession(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class);
    }
}
