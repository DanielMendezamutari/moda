<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles + usuarios admin/cajero (sin sucursal ni catálogo demo).
        $this->call(BasicUsersSeeder::class);

        // Opcional: datos demo (sucursal PRIN, almacenes, categorías, etc.).
        // $this->call(BranchSeeder::class);
        // $this->call(WarehouseSeeder::class);
        // $this->call(CategorySeeder::class);
        // $this->call(SupplierSeeder::class);
        // $this->call(UnitSeeder::class);
        // $this->call(PosDemoLoginSeeder::class);
    }
}
