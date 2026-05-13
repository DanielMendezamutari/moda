<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(BranchSeeder::class);
});

test('guest can list public catalog products', function () {
    Product::factory()->create(['name' => 'Vestido A', 'is_active' => true]);
    Product::factory()->create(['name' => 'Oculto', 'is_active' => false]);

    $names = $this->getJson('/api/public/catalog/products')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toContain('Vestido A')->not->toContain('Oculto');
});

test('store register creates store customer client and jwt includes roles', function () {
    $res = $this->postJson('/api/store/register', [
        'name' => 'Ana',
        'surname' => 'Pérez',
        'email' => 'ana@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])
        ->assertOk()
        ->assertJsonStructure(['access_token']);

    $user = User::where('email', 'ana@example.com')->firstOrFail();
    expect($user->hasRole('store_customer'))->toBeTrue()
        ->and($user->clientRecord)->not->toBeNull();

    $payload = JWTAuth::setToken($res->json('access_token'))->getPayload();

    expect($payload->get('roles'))->toContain('store_customer');
});

test('staff user cannot login via store login', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => Hash::make('secret'),
    ]);
    $user->assignRole('admin');

    $this->postJson('/api/store/login', [
        'email' => 'admin@example.com',
        'password' => 'secret',
    ])
        ->assertStatus(422);
});

test('store customer can add to cart and merge guest items', function () {
    $branch = Branch::query()->firstOrFail();
    $warehouse = Warehouse::query()->create([
        'name' => 'Alm catálogo',
        'address' => 'Calle 1',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);

    $product = Product::factory()->create(['is_active' => true, 'price' => 100]);
    $product->warehouses()->attach($warehouse->id, [
        'stock' => 10,
        'umbral' => 0,
        'unit_id' => null,
    ]);

    $reg = $this->postJson('/api/store/register', [
        'name' => 'Luis',
        'email' => 'luis@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertOk();

    $token = $reg->json('access_token');

    $this->withToken($token)
        ->postJson('/api/store/cart/items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('totals.items_count', 2);

    $this->withToken($token)
        ->postJson('/api/store/cart/merge', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('totals.items_count', 3);
});
