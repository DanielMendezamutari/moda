<?php

use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can list suppliers', function () {
    Supplier::create([
        'name' => 'Textiles ABC',
        'ruc' => '1111111111111',
        'phone' => '70123456',
        'address' => 'Zona Sur',
        'is_active' => true,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/suppliers')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'ruc',
                    'email',
                    'phone',
                    'address',
                    'is_active',
                    'contact_name',
                    'phone_alt',
                    'website',
                    'city',
                    'department',
                    'country',
                    'payment_terms',
                    'notes',
                    'created_at',
                ],
            ],
        ]);
});

test('admin can create supplier with optional fields', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/suppliers', [
            'name' => 'Importadora XYZ',
            'ruc' => '2222222222222',
            'email' => 'contacto@xyz.test',
            'phone' => '71234567',
            'address' => 'Calle Comercio 100',
            'is_active' => true,
            'contact_name' => 'María López',
            'phone_alt' => '78888888',
            'website' => 'https://xyz.test',
            'city' => 'Santa Cruz',
            'department' => 'Santa Cruz',
            'country' => 'Bolivia',
            'payment_terms' => 'Contado / 30 días según OC',
            'notes' => 'Entrega martes y jueves.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Importadora XYZ');

    expect(Supplier::where('ruc', '2222222222222')->exists())->toBeTrue();
});

test('cashier cannot create supplier', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/suppliers', [
            'name' => 'X',
            'ruc' => '3333333333333',
            'phone' => '1',
            'address' => 'Y',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('admin can update supplier', function () {
    $supplier = Supplier::create([
        'name' => 'Temporal',
        'ruc' => '4444444444444',
        'phone' => '70000001',
        'address' => 'Dir',
        'is_active' => true,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->patchJson("/api/suppliers/{$supplier->id}", [
            'name' => 'Temporal actualizado',
            'email' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Temporal actualizado');
});

test('admin can delete supplier', function () {
    $supplier = Supplier::create([
        'name' => 'Borrar',
        'ruc' => '5555555555555',
        'phone' => '70000002',
        'address' => 'Dir',
        'is_active' => true,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->deleteJson("/api/suppliers/{$supplier->id}")
        ->assertOk();

    expect(Supplier::find($supplier->id))->toBeNull();
});
