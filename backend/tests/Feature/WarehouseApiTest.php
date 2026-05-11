<?php

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(BranchSeeder::class);
});

test('admin can list warehouses', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::first();

    Warehouse::create([
        'name' => 'Almacén test',
        'address' => 'Calle 1',
        'branch_id' => $branch->id,
        'state' => 'La Paz',
    ]);

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/warehouses')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'address', 'branch_id', 'state', 'branch', 'created_at'],
            ],
        ]);
});

test('admin can create warehouse', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::first();

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/warehouses', [
            'name' => 'Almacén norte',
            'address' => 'Zona industrial 200',
            'branch_id' => $branch->id,
            'state' => 'Cochabamba',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Almacén norte');

    expect(Warehouse::where('name', 'Almacén norte')->exists())->toBeTrue();
});

test('admin cannot create warehouse with invalid bolivia department state', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::first();

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/warehouses', [
            'name' => 'Almacén inválido',
            'address' => 'Calle 2',
            'branch_id' => $branch->id,
            'state' => 'Atlántida',
        ])
        ->assertUnprocessable();
});

test('cashier cannot create warehouse', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $branch = Branch::first();

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/warehouses', [
            'name' => 'X',
            'address' => 'Y',
            'branch_id' => $branch->id,
            'state' => 'Z',
        ])
        ->assertForbidden();
});
