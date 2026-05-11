<?php

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
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
function saleTestFixtures(): object
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

function openTestCashSession(int $branchId, int $userId): int
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

test('admin can create sale and stock is decremented', function () {
    $f = saleTestFixtures();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 3,
                'unit_price' => 10.5,
            ]],
            'payments' => [
                ['method_payment' => 'efectivo', 'amount' => 31.5],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.state_payment', 'paid')
        ->assertJsonPath('data.debt', '0.00');

    $stock = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');

    expect($stock)->toBe(7);
});

test('cashier cannot sell from another branch warehouse', function () {
    $f = saleTestFixtures();
    $cashier = User::factory()->create(['branch_id' => $f->b1->id]);
    $cashier->assignRole('cashier');

    $sid = openTestCashSession($f->b1->id, $cashier->id);

    $this->actingAs($cashier, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w2->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.warehouse_id']);
});

test('cashier can create sale in own branch', function () {
    $f = saleTestFixtures();
    $cashier = User::factory()->create(['branch_id' => $f->b1->id]);
    $cashier->assignRole('cashier');

    $sid = openTestCashSession($f->b1->id, $cashier->id);

    $this->actingAs($cashier, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])
        ->assertCreated();
});

test('sale with credito payment charges client account', function () {
    $f = saleTestFixtures();
    $client = Client::factory()->create([
        'branch_id' => $f->b1->id,
        'credit_enabled' => true,
        'credit_limit' => 1000,
        'credit_balance' => 0,
    ]);
    $cashier = User::factory()->create(['branch_id' => $f->b1->id]);
    $cashier->assignRole('cashier');

    $sid = openTestCashSession($f->b1->id, $cashier->id);

    $this->actingAs($cashier, 'api')
        ->postJson('/api/sales', [
            'client_id' => $client->id,
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            'payments' => [
                ['method_payment' => 'credito', 'amount' => 100],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.state_payment', 'paid');

    $client->refresh();

    expect((float) $client->credit_balance)->toBe(100.0);
});

test('credito payment without client is rejected', function () {
    $f = saleTestFixtures();
    $cashier = User::factory()->create(['branch_id' => $f->b1->id]);
    $cashier->assignRole('cashier');

    $sid = openTestCashSession($f->b1->id, $cashier->id);

    $this->actingAs($cashier, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            'payments' => [
                ['method_payment' => 'credito', 'amount' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payments.0.method_payment']);
});

test('admin can register follow-up payment', function () {
    $f = saleTestFixtures();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
            'payments' => [['method_payment' => 'efectivo', 'amount' => 40]],
        ])
        ->assertCreated();

    $saleId = $res->json('data.id');

    $this->actingAs($admin, 'api')
        ->postJson("/api/sales/{$saleId}/payments", [
            'method_payment' => 'transferencia',
            'amount' => 60,
            'n_transaction' => 'TRX-1',
        ])
        ->assertCreated()
        ->assertJsonPath('data.state_payment', 'paid')
        ->assertJsonPath('data.debt', '0.00');

    expect(DB::table('sale_payments')->where('sale_id', $saleId)->count())->toBe(2);
});

test('sale ticket pdf is returned as inline pdf', function () {
    $f = saleTestFixtures();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 10,
            ]],
            'payments' => [['method_payment' => 'efectivo', 'amount' => 10]],
        ])
        ->assertCreated();

    $saleId = $res->json('data.id');

    $this->actingAs($admin, 'api')
        ->get("/api/sales/{$saleId}/ticket")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('admin cancel sale restores stock and removes cash movements', function () {
    $f = saleTestFixtures();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $sid = openTestCashSession($f->b1->id, $admin->id);

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 2,
                'unit_price' => 10,
            ]],
            'payments' => [
                ['method_payment' => 'efectivo', 'amount' => 20],
            ],
        ])
        ->assertCreated();

    $saleId = (int) $res->json('data.id');

    $stockBeforeCancel = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');

    expect($stockBeforeCancel)->toBe(8);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/sales/{$saleId}")
        ->assertNoContent();

    $sale = Sale::query()->findOrFail($saleId);
    expect($sale->state_sale)->toBe('cancelled');

    $stockAfter = (int) DB::table('product_warehouses')
        ->where('product_id', $f->product->id)
        ->where('warehouse_id', $f->w1->id)
        ->value('stock');

    expect($stockAfter)->toBe(10);

    $payIds = DB::table('sale_payments')->where('sale_id', $saleId)->pluck('id');

    expect(DB::table('cash_movements')->whereIn('sale_payment_id', $payIds)->count())->toBe(0);
});

test('cancel sale reverses cliente credito charges', function () {
    $f = saleTestFixtures();
    $client = Client::factory()->create([
        'branch_id' => $f->b1->id,
        'credit_enabled' => true,
        'credit_limit' => 1000,
        'credit_balance' => 0,
    ]);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $sid = openTestCashSession($f->b1->id, $admin->id);

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/sales', [
            'client_id' => $client->id,
            'igv' => 0,
            'cash_register_session_id' => $sid,
            'items' => [[
                'product_id' => $f->product->id,
                'warehouse_id' => $f->w1->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
            'payments' => [
                ['method_payment' => 'credito', 'amount' => 50],
            ],
        ])
        ->assertCreated();

    $saleId = (int) $res->json('data.id');

    $client->refresh();
    expect((float) $client->credit_balance)->toBe(50.0);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/sales/{$saleId}")
        ->assertNoContent();

    $client->refresh();
    expect((float) $client->credit_balance)->toBe(0.0);
});
