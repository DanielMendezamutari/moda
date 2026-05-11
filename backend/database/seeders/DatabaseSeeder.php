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
        $this->call(RolePermissionSeeder::class);
        $this->call(BranchSeeder::class);
        $this->call(WarehouseSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(SupplierSeeder::class);
        $this->call(UnitSeeder::class);
        $this->call(PosDemoLoginSeeder::class);
    }
}
