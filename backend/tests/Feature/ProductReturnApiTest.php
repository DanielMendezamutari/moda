<?php

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ProductReturn;
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
 * @return object{b1: Branch, b2: Branch, w1: Warehouse, w2: Warehouse, product: Product}
 */
function productReturnFixtures(): object
{
    $b1 = Branch::factory()->create();
    $b2 = Branch::factory()->create();
    $w1 = Warehouse::create([
        'name' => 'Almacén S1',
        'address' => 'Calle 1',
        'branch_id' => $b1->id,
        'state' => 'La Paz',
    ]);
    $w2 = Warehouse::create([
        'name' => 'Almacén S2',
        'address' => 'Calle 2',
        'branch_id' => $b2->id,
        'state' => 'Cochabamba',
    ]);
    $product = Product::factory()->create();
    $product->warehouses()->attach($w1->id, ['stock' => 10, 'umbral' => 0]);
    $product->warehouses()->attach($w2->id, ['stock' => 5, 'umbral' => 0]);

    return (object) [
        'b1' => $b1,
        'b2' => $b2,
        'w1' => $w1,
        'w2' => $w2,
        'product' => $product,
    ];
}

function openReturnTestCashSession(int $branchId, int $userId): int
{
    $cr = CashRegister::query()->create([
        'branch_id' => $branchId,
        'name' => 'Caja test',
        'is_active' => true,
    ]);

    $session = CashRegisterSession::query()->create([
        'cash_register_id' => $cr->id,
        'opened_by_user_id' => $userId,
        'opened_at' => now(),
        'opening_float' => 0,
        'status' => 'open',
    ]);

    return $session->id;
}

test('cashier can create return and stock increases when marked reparado', function () {
    $f = productReturnFixtures();
    $cashier = User::factory()->create(['branch_id' => $f->b1->id]);
    $cashier->assignRole('cashier');

    $sid = openReturnTestCashSession($f->b1->id, $cashier->id);

    $saleRes = $this->actingAs($cashier, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 5,
                'unit_price' => 10,
            ]],
            'payments' => [['method_payment' => 'efectivo', 'amount' => 50]],
        ])
        ->assertCreated();

    $saleData = $saleRes->json('data');
    $detailId = (int) ($saleData['items'][0]['id'] ?? $saleData['sale_details'][0]['id'] ?? 0);
    expect($detailId)->toBeGreaterThan(0);

    $stockAfterSale = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');
    expect($stockAfterSale)->toBe(5);

    $ret = $this->actingAs($cashier, 'api')
        ->postJson('/api/product-returns', [
            'sale_detail_id' => $detailId,
            'quantity' => 2,
            'type' => ProductReturn::TYPE_DEVOLUCION,
            'state' => ProductReturn::STATE_PENDIENTE,
            'description' => 'Cliente devolvió',
        ])
        ->assertCreated()
        ->json('data');

    expect((int) $ret['id'])->toBeGreaterThan(0);

    $stockPending = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');
    expect($stockPending)->toBe(5);

    $this->actingAs($cashier, 'api')
        ->putJson('/api/product-returns/'.$ret['id'], [
            'state' => ProductReturn::STATE_REPARADO,
        ])
        ->assertOk();

    $stockFinal = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');
    expect($stockFinal)->toBe(7);
});

test('return quantity cannot exceed sale line', function () {
    $f = productReturnFixtures();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $saleRes = $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 2,
                'unit_price' => 10,
            ]],
        ])
        ->assertCreated();

    $detailId = (int) $saleRes->json('data.items.0.id');

    $this->actingAs($admin, 'api')
        ->postJson('/api/product-returns', [
            'sale_detail_id' => $detailId,
            'quantity' => 3,
            'type' => ProductReturn::TYPE_DEVOLUCION,
            'state' => ProductReturn::STATE_PENDIENTE,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});
