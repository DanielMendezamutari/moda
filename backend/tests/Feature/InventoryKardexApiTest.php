<?php

use App\Models\Branch;
use App\Models\InventoryKardexEntry;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @return object{branch: Branch, warehouse: Warehouse, product: Product}
 */
function kardexTestContext(): object
{
    $branch = Branch::factory()->create();
    $warehouse = Warehouse::create([
        'name' => 'Almacén kardex',
        'address' => 'Calle',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);
    $product = Product::factory()->create();
    $product->warehouses()->attach($warehouse->id, ['stock' => 0, 'umbral' => 0]);

    return (object) [
        'branch' => $branch,
        'warehouse' => $warehouse,
        'product' => $product,
    ];
}

test('guest cannot view kardex', function () {
    $ctx = kardexTestContext();

    $this->getJson("/api/inventory/kardex?product_id={$ctx->product->id}&warehouse_id={$ctx->warehouse->id}")
        ->assertUnauthorized();
});

test('cashier without kardex permission cannot view kardex', function () {
    $ctx = kardexTestContext();
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $this->actingAs($cashier, 'api')
        ->getJson("/api/inventory/kardex?product_id={$ctx->product->id}&warehouse_id={$ctx->warehouse->id}")
        ->assertForbidden();
});

test('admin can view kardex with empty rows', function () {
    $ctx = kardexTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson("/api/inventory/kardex?product_id={$ctx->product->id}&warehouse_id={$ctx->warehouse->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $ctx->product->id)
        ->assertJsonPath('data.warehouse_id', $ctx->warehouse->id)
        ->assertJsonPath('data.sections.0.rows', []);
});

test('kardex lists COMPRAS after purchase line is entregado', function () {
    $ctx = kardexTestContext();
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
                'price_unit' => 10,
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

    $this->actingAs($admin, 'api')
        ->getJson("/api/inventory/kardex?product_id={$ctx->product->id}&warehouse_id={$ctx->warehouse->id}")
        ->assertOk()
        ->assertJsonFragment(['detail_label' => 'COMPRAS']);
});

test('backfill command inserts COMPRAS row when delivery had stock but kardex was lost', function () {
    $ctx = kardexTestContext();
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
                'price_unit' => 10,
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

    expect(InventoryKardexEntry::query()->where('reference_type', 'purchase_item')->count())->toBe(1);

    InventoryKardexEntry::query()->delete();

    Artisan::call('inventory:backfill-purchase-kardex');
    expect(Artisan::output())->toContain('Insertadas');

    expect(InventoryKardexEntry::query()->where('reference_type', 'purchase_item')->count())->toBe(1);
});

test('admin can load product ledger by product_id across warehouses', function () {
    $ctx = kardexTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson("/api/inventory/kardex/product-ledger?product_id={$ctx->product->id}")
        ->assertOk()
        ->assertJsonPath('data.product.id', $ctx->product->id)
        ->assertJsonPath('data.warehouses.0.warehouse_id', $ctx->warehouse->id)
        ->assertJsonStructure([
            'data' => [
                'product' => ['id', 'name', 'sku', 'barcode'],
                'view_types',
                'warehouses' => [
                    '*' => ['warehouse_id', 'warehouse', 'summary', 'sections'],
                ],
            ],
        ]);
});

test('admin can load product ledger by barcode', function () {
    $ctx = kardexTestContext();
    $ctx->product->update(['barcode' => 'INVSCAN-TEST-001']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson('/api/inventory/kardex/product-ledger?barcode=INVSCAN-TEST-001')
        ->assertOk()
        ->assertJsonPath('data.product.id', $ctx->product->id);
});

test('product ledger returns 422 without barcode or product_id', function () {
    $ctx = kardexTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson('/api/inventory/kardex/product-ledger')
        ->assertStatus(422);
});

test('product ledger returns 404 for unknown barcode', function () {
    $ctx = kardexTestContext();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson('/api/inventory/kardex/product-ledger?barcode=__NO_EXISTE__')
        ->assertNotFound();
});
