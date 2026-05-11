<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashRegister extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'default_user_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function defaultUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_user_id');
    }

    /**
     * @return HasMany<CashRegisterSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(CashRegisterSession::class);
    }

    public function openSession(): ?CashRegisterSession
    {
        return $this->sessions()->where('status', 'open')->first();
    }
}
