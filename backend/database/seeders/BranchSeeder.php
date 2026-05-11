<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::updateOrCreate(
            ['code' => 'PRIN'],
            [
                'name' => 'Sucursal principal',
                'address' => 'Dirección principal (demo)',
                'state' => 'La Paz',
                'is_active' => true,
            ],
        );
    }
}
