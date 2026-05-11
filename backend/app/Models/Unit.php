<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /**
     * @var list<string>
     */
    public const DIMENSION_COUNT = 'count';

    public const DIMENSION_LENGTH = 'length';

    public const DIMENSION_MASS = 'mass';

    public const DIMENSION_VOLUME = 'volume';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'dimension',
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
     * @return HasMany<UnitConversion, $this>
     */
    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'from_unit_id');
    }

    /**
     * @return HasMany<UnitConversion, $this>
     */
    public function conversionsTo(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'to_unit_id');
    }
}
