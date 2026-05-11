<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'ruc',
        'email',
        'phone',
        'address',
        'is_active',
        'contact_name',
        'phone_alt',
        'website',
        'city',
        'department',
        'country',
        'payment_terms',
        'notes',
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
}
