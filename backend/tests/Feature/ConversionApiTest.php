<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can register conversion and stock adjusts by delta', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::create([
        'name' => 'Alm conv',
        'address' => 'X',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);

    $uBox = Unit::create(['name' => 'Caja', 'dimension' => 'count', 'is_active' => true]);
    $uUnit = Unit::create(['name' => 'Unidad', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $uBox->id,
        'to_unit_id' => $uUnit->id,
        'factor' => 12,
    ]);

    $product = Product::factory()->create();
    $product->warehouses()->attach($warehouse->id, [
        'stock' => 100,
        'umbral' => 0,
        'unit_id' => $uUnit->id,
    ]);

    $this->actingAs($admin, 'api')
        ->postJson('/api/conversions', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'unit_start_id' => $uBox->id,
            'unit_end_id' => $uUnit->id,
            'quantity_start' => 1,
            'quantity_end' => 10,
            'description' => 'Merma al abrir caja',
        ])
        ->assertCreated()
        ->assertJsonPath('data.stock_delta', '-2.0000');

    $stock = (int) DB::table('product_warehouses')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('stock');
    expect($stock)->toBe(98);

    expect((int) DB::table('inventory_kardex_entries')
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->where('movement_type', 'conversion')
        ->count())->toBe(1);
});

test('admin cannot delete product with conversion record', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::factory()->create();
    $warehouse = Warehouse::create([
        'name' => 'Alm',
        'address' => 'X',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);

    $uA = Unit::create(['name' => 'A', 'dimension' => 'count', 'is_active' => true]);
    $uB = Unit::create(['name' => 'B', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $uA->id,
        'to_unit_id' => $uB->id,
        'factor' => 1,
    ]);

    $product = Product::factory()->create();
    $product->warehouses()->attach($warehouse->id, [
        'stock' => 10,
        'umbral' => 0,
        'unit_id' => $uB->id,
    ]);

    $this->actingAs($admin, 'api')
        ->postJson('/api/conversions', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'unit_start_id' => $uA->id,
            'unit_end_id' => $uB->id,
            'quantity_start' => 2,
            'quantity_end' => 2,
        ])
        ->assertCreated();

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product']);
});
