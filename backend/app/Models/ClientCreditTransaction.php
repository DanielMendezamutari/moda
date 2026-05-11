<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCreditTransaction extends Model
{
    public const TYPE_CHARGE = 'charge';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
