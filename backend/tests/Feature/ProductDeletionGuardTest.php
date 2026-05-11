<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Transport;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can delete product without document lines', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $product = Product::factory()->create();

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/products/{$product->id}")
        ->assertNoContent();

    expect(Product::query()->whereKey($product->id)->exists())->toBeFalse();
});

test('admin cannot delete product with sale line', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $product = Product::factory()->create();
    $sale = Sale::factory()->create(['user_id' => $admin->id]);
    DB::table('sale_details')->insert([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product']);

    expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
});

test('admin cannot delete product with purchase line', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $product = Product::factory()->create();
    $purchase = Purchase::query()->create(['supplier_id' => null, 'reference' => 'OC-TEST']);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_cost' => 5.5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product']);

    expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
});

test('admin cannot delete product with transport line', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $branch = Branch::factory()->create();
    $w1 = Warehouse::create([
        'name' => 'Alm origen',
        'address' => 'Calle 1',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $w2 = Warehouse::create([
        'name' => 'Alm destino',
        'address' => 'Calle 2',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $product = Product::factory()->create();
    $transport = Transport::query()->create([
        'warehouse_start_id' => $w1->id,
        'warehouse_end_id' => $w2->id,
        'user_id' => $admin->id,
        'state' => Transport::STATE_SOLICITUD,
        'importe' => 0,
        'igv' => 0,
        'total' => 0,
        'reference' => 'TR-TEST',
    ]);
    DB::table('transport_details')->insert([
        'transport_id' => $transport->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'price_unit' => 0,
        'line_total' => 0,
        'state' => 'solicitud',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/products/{$product->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product']);

    expect(Product::query()->whereKey($product->id)->exists())->toBeTrue();
});
