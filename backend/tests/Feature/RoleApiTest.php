<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can list roles with permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $response = $this->withToken($token)->getJson('/api/roles');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'display_name', 'permissions', 'created_at', 'is_system'],
            ],
        ]);

    expect($response->json('data'))->not->toBeEmpty();
});

test('admin can create role with permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/roles', [
            'name' => 'supervisor',
            'permissions' => ['pos.sale.view', 'reports.view'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'supervisor');

    expect(\Spatie\Permission\Models\Role::findByName('supervisor', 'api'))
        ->not->toBeNull();
});

test('cashier cannot create roles', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/roles', ['name' => 'x'])
        ->assertForbidden();
});

test('admin can update role permissions', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $role = Role::findOrCreate('supervisor', 'api');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->patchJson("/api/roles/{$role->id}", [
            'permissions' => ['pos.sale.view'],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'supervisor');

    expect($role->fresh()->permissions)->not->toBeEmpty();
});

test('admin cannot delete role with assigned users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $role = Role::findOrCreate('con-usuarios', 'api');
    $other = User::factory()->create();
    $other->assignRole('con-usuarios');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422);
});

test('admin cannot delete system roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $role = Role::findByName('admin', 'api');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->deleteJson("/api/roles/{$role->id}")
        ->assertStatus(422);
});

test('cashier cannot list roles', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/roles')
        ->assertForbidden();
});
