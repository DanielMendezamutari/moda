<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(BranchSeeder::class);
});

test('admin can list branches with full fields', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/branches')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'code', 'address', 'state', 'is_active', 'created_at'],
            ],
        ]);
});

test('admin can create branch', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/branches', [
            'name' => 'Sucursal Norte',
            'code' => 'NTE',
            'address' => 'Av. Principal 123',
            'state' => 'Santa Cruz',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'NTE');

    expect(Branch::where('code', 'NTE')->exists())->toBeTrue();
});

test('admin cannot create branch with invalid bolivia department state', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/branches', [
            'name' => 'Sucursal inválida',
            'address' => 'Calle 1',
            'state' => 'No es un departamento de Bolivia',
            'is_active' => true,
        ])
        ->assertUnprocessable();
});

test('admin can update branch', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::where('code', 'PRIN')->firstOrFail();

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->patchJson("/api/branches/{$branch->id}", [
            'name' => 'Sucursal principal actualizada',
            'address' => $branch->address ?? 'Nueva dirección',
            'state' => $branch->state ?? 'La Paz',
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Sucursal principal actualizada');
});

test('cashier cannot create branches', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/branches', [
            'name' => 'X',
            'address' => 'Y',
            'state' => 'Z',
            'is_active' => true,
        ])
        ->assertForbidden();
});
