<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\Transport;
use App\Models\TransportDetail;
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
 * @return object{branch: Branch, w1: Warehouse, w2: Warehouse, product: Product}
 */
function transportTestContext(): object
{
    $branch = Branch::factory()->create();
    $w1 = Warehouse::create([
        'name' => 'Almacén A',
        'address' => 'Dir A',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $w2 = Warehouse::create([
        'name' => 'Almacén B',
        'address' => 'Dir B',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $product = Product::factory()->create();
    $product->warehouses()->attach($w1->id, ['stock' => 10, 'umbral' => 0]);
    $product->warehouses()->attach($w2->id, ['stock' => 2, 'umbral' => 0]);

    return (object) ['branch' => $branch, 'w1' => $w1, 'w2' => $w2, 'product' => $product];
}

test('admin can create transport and list', function () {
    $ctx = transportTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/transports', [
            'warehouse_start_id' => $ctx->w1->id,
            'warehouse_end_id' => $ctx->w2->id,
            'igv' => 0,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 2,
                'price_unit' => 5,
            ]],
        ])
        ->assertCreated();

    $data = $res->json('data');
    expect($data['importe'])->toBe('10.00')
        ->and($data['state'])->toBe(Transport::STATE_SOLICITUD)
        ->and($data['items'])->toHaveCount(1);

    $this->actingAs($admin, 'api')
        ->getJson('/api/transports')
        ->assertOk()
        ->assertJsonFragment(['id' => $data['id']]);
});

test('line salida decrements origin stock and entrega increments destination', function () {
    $ctx = transportTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/transports', [
            'warehouse_start_id' => $ctx->w1->id,
            'warehouse_end_id' => $ctx->w2->id,
            'igv' => 0,
            'items' => [[
                'product_id' => $ctx->product->id,
                'quantity' => 3,
                'price_unit' => 1,
            ]],
        ])
        ->assertCreated();

    $tid = (int) $res->json('data.id');
    $lineId = (int) $res->json('data.items.0.id');

    $this->actingAs($admin, 'api')
        ->patchJson("/api/transports/{$tid}/details/{$lineId}", [
            'state' => TransportDetail::STATE_SALIDA,
        ])
        ->assertOk();

    $stockOrigin = (int) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->w1->id)
        ->value('stock');
    expect($stockOrigin)->toBe(7);

    expect((int) DB::table('inventory_kardex_entries')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->w1->id)
        ->where('movement_type', 'transport_out')
        ->count())->toBe(1);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/transports/{$tid}/details/{$lineId}", [
            'state' => TransportDetail::STATE_ENTREGA,
        ])
        ->assertOk();

    $stockDest = (int) DB::table('product_warehouses')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->w2->id)
        ->value('stock');
    expect($stockDest)->toBe(5);

    expect((int) DB::table('inventory_kardex_entries')
        ->where('product_id', $ctx->product->id)
        ->where('warehouse_id', $ctx->w2->id)
        ->where('movement_type', 'transport_in')
        ->count())->toBe(1);

    $this->actingAs($admin, 'api')
        ->getJson("/api/transports/{$tid}")
        ->assertOk()
        ->assertJsonPath('data.state', Transport::STATE_ENTREGA);
});
