<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('cashier can list products but cannot create', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    Product::factory()->count(2)->create();

    $this->actingAs($user, 'api')
        ->getJson('/api/products')
        ->assertOk();

    $this->actingAs($user, 'api')->postJson('/api/products', [
        'sku' => 'NEW-001',
        'name' => 'Test',
        'price' => 10,
        'is_active' => true,
        'is_gift_card' => false,
    ])->assertForbidden();
});

test('admin can create products', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $branch = Branch::query()->create([
        'name' => 'Sucursal test',
        'code' => 'ST1',
    ]);
    $warehouse = Warehouse::query()->create([
        'name' => 'Almacén test',
        'address' => 'Dirección 1',
        'branch_id' => $branch->id,
        'state' => 'Activo',
    ]);

    $this->actingAs($user, 'api')->postJson('/api/products', [
        'sku' => 'ADM-001',
        'name' => 'Admin product',
        'price' => 99.99,
        'stock' => 5,
        'umbral' => 2,
        'warehouse_id' => $warehouse->id,
        'is_active' => true,
        'is_gift_card' => false,
        'discount_percent' => 0,
        'warranty_days' => 30,
    ])->assertCreated();
});

test('cashier cannot view reports', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $this->actingAs($user, 'api')
        ->getJson('/api/reports')
        ->assertForbidden();
});

test('admin can view reports', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user, 'api')
        ->getJson('/api/reports')
        ->assertOk();
});

test('guest cannot access products', function () {
    Product::factory()->create();

    $this->getJson('/api/products')->assertUnauthorized();
});
