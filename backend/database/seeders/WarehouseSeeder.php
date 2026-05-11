<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('code', 'PRIN')->first();
        if (! $branch)
            return;

        Warehouse::firstOrCreate(
            [
                'branch_id' => $branch->id,
                'name' => 'Almacén principal',
            ],
            [
                'address' => 'Ubicación en sede principal (demo)',
                'state' => $branch->state ?? 'La Paz',
            ],
        );
    }
}
