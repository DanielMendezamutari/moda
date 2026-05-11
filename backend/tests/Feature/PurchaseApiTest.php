<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
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

/**
 * @return object{branch: Branch, warehouse: Warehouse, product: Product}
 */
function purchaseTestContext(): object
{
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::create([
        'name' => 'Almacén compras',
        'address' => 'Calle',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $product = Product::factory()->create();
    $product->warehouses()->attach($warehouse->id, ['stock' => 5, 'umbral' => 0]);

    return (object) [
        'branch' => $branch,
        'warehouse' => $warehouse,
        'product' => $product,
    ];
}

test('admin can create purchase and list', function () {
    $ctx = purchaseTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/purchases', [
            'warehouse_id' => $ctx->warehouse->id,
            'requester_id' => $admin->id,
            'igv' => 1,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 2,
                'price_unit' => 10,
            ]],
        ])
        ->assertCreated();

    $data = $res->json('data');
    expect($data['importe'])->toBe('20.00')
        ->and($data['total'])->toBe('21.00')
        ->and($data['state'])->toBe(Purchase::STATE_SOLICITUD);

    $this->actingAs($admin, 'api')
        ->getJson('/api/purchases')
        ->assertOk()
        ->assertJsonFragment(['id' => $data['id']]);
});

test('marking purchase line entregado increases stock', function () {
    $ctx = purchaseTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/purchases', [
            'warehouse_id' => $ctx->warehouse->id,
            'requester_id' => $admin->id,
            'igv' => 0,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 3,
                'price_unit' => 4,
            ]],
        ])
        ->assertCreated();

    $purchaseId = (int) $res->json('data.id');
    $lineId = (int) $res->json('data.items.0.id');

    $stockBefore = (int) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->warehouse->id)
        ->value('stock');
    expect($stockBefore)->toBe(5);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/purchases/{$purchaseId}/items/{$lineId}", [
            'state' => PurchaseItem::STATE_ENTREGADO,
        ])
        ->assertOk();

    $stockAfter = (int) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->warehouse->id)
        ->value('stock');
    expect($stockAfter)->toBe(8);

    $this->actingAs($admin, 'api')
        ->getJson("/api/purchases/{$purchaseId}")
        ->assertOk()
        ->assertJsonPath('data.state', Purchase::STATE_ENTREGADO);
});

test('creating purchase can update product and warehouse sale price when requested', function () {
    $ctx = purchaseTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $ctx->product->price = 100;
    $ctx->product->save();

    $this->actingAs($admin, 'api')
        ->postJson('/api/purchases', [
            'warehouse_id' => $ctx->warehouse->id,
            'requester_id' => $admin->id,
            'igv' => 0,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 1,
                'price_unit' => 5,
                'update_sale_price' => true,
                'new_sale_price' => 12.34,
            ]],
        ])
        ->assertCreated();

    $ctx->product->refresh();
    expect((float) $ctx->product->price)->toBe(12.34);

    $sp = (float) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->warehouse->id)
        ->value('sale_price');
    expect($sp)->toBe(12.34);
});

test('delivered purchase line converts quantity to warehouse stock unit', function () {
    $ctx = purchaseTestContext();

    $piece = Unit::query()->create([
        'name' => 'Pieza conv '.uniqid(),
        'dimension' => Unit::DIMENSION_COUNT,
        'is_active' => true,
    ]);
    $dozen = Unit::query()->create([
        'name' => 'Docena conv '.uniqid(),
        'dimension' => Unit::DIMENSION_COUNT,
        'is_active' => true,
    ]);
    UnitConversion::query()->create([
        'from_unit_id' => $dozen->id,
        'to_unit_id' => $piece->id,
        'factor' => 12,
    ]);

    $ctx->product->warehouses()->sync([
        $ctx->warehouse->id => [
            'stock' => 10,
            'umbral' => 0,
            'unit_id' => $piece->id,
        ],
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/purchases', [
            'warehouse_id' => $ctx->warehouse->id,
            'requester_id' => $admin->id,
            'igv' => 0,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 2,
                'price_unit' => 120,
                'unit_id' => $dozen->id,
            ]],
        ])
        ->assertCreated();

    $purchaseId = (int) $res->json('data.id');
    $lineId = (int) $res->json('data.items.0.id');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/purchases/{$purchaseId}/items/{$lineId}", [
            'state' => PurchaseItem::STATE_ENTREGADO,
        ])
        ->assertOk();

    $stock = (int) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->warehouse->id)
        ->value('stock');

    expect($stock)->toBe(10 + 24);
});
