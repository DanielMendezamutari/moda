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

test('admin can list users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $response = $this->withToken($token)->getJson('/api/users');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'email',
                    'document_number',
                    'gender',
                    'gender_label',
                    'is_active',
                    'branch_id',
                    'branch',
                    'roles',
                    'role',
                    'role_display',
                    'avatar_url',
                    'avatar_preset',
                    'created_at',
                ],
            ],
        ]);
});

test('admin can create user with branch and role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $branch = Branch::where('code', 'PRIN')->firstOrFail();

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/users', [
            'name' => 'Usuario Test',
            'email' => 'test.user@example.com',
            'password' => 'password123',
            'pin' => '5678',
            'branch_id' => $branch->id,
            'document_number' => '87654321',
            'gender' => 'male',
            'is_active' => true,
            'role' => 'cashier',
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'test.user@example.com')
        ->assertJsonPath('data.role', 'cashier');

    expect(User::where('email', 'test.user@example.com')->first())
        ->not->toBeNull()
        ->hasRole('cashier')->toBeTrue();
});

test('admin can update user name using post form data', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $target = User::factory()->create(['name' => 'Nombre viejo']);
    $target->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)->post("/api/users/{$target->id}", [
        'name' => 'Nombre nuevo',
        'email' => $target->email,
        'document_number' => $target->document_number,
        'gender' => $target->gender ?? 'male',
        'is_active' => true,
        'role' => 'cashier',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nombre nuevo');

    expect($target->fresh()->name)->toBe('Nombre nuevo');
});

test('cashier cannot list users', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/users')
        ->assertForbidden();
});

test('admin can list branches', function () {
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
