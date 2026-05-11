<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::updateOrCreate(
            ['ruc' => '1234567890123'],
            [
                'name' => 'Proveedor demo S.R.L.',
                'email' => null,
                'phone' => '70000000',
                'address' => 'Av. Principal s/n, Zona industrial (demo)',
                'is_active' => true,
                'city' => 'La Paz',
                'department' => 'La Paz',
                'country' => 'Bolivia',
            ],
        );
    }
}
