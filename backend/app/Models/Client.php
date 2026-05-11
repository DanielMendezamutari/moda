<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'full_name',
        'phone',
        'email',
        'type_client',
        'type_document',
        'n_document',
        'birthdate',
        'user_id',
        'branch_id',
        'is_active',
        'gender',
        'ubigeo',
        'address',
        'credit_enabled',
        'credit_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'is_active' => 'boolean',
            'credit_enabled' => 'boolean',
            'credit_limit' => 'decimal:2',
            'credit_balance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Client $client): void {
            $n = trim((string) $client->name);
            $s = trim((string) ($client->surname ?? ''));
            $client->full_name = $s !== '' ? "{$n} {$s}" : $n;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ClientCreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(ClientCreditTransaction::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
