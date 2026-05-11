<?php

use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function adminToken(): string
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return test()->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');
}

test('admin can list units', function () {
    Unit::create(['name' => 'Caja', 'dimension' => 'count', 'is_active' => true]);

    $this->withToken(adminToken())
        ->getJson('/api/units')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'description', 'dimension', 'dimension_label', 'is_active', 'created_at'],
            ],
        ]);
});

test('admin can create unit', function () {
    $this->withToken(adminToken())
        ->postJson('/api/units', [
            'name' => 'Par',
            'description' => 'Par de artículos',
            'dimension' => 'count',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Par')
        ->assertJsonPath('data.dimension', 'count');
});

test('admin cannot delete unit with conversions', function () {
    $a = Unit::create(['name' => 'A', 'dimension' => 'count', 'is_active' => true]);
    $b = Unit::create(['name' => 'B', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $a->id,
        'to_unit_id' => $b->id,
        'factor' => 2,
    ]);

    $this->withToken(adminToken())
        ->deleteJson("/api/units/{$a->id}")
        ->assertUnprocessable();
});

test('admin can list conversions for unit', function () {
    $a = Unit::create(['name' => 'A', 'dimension' => 'count', 'is_active' => true]);
    $b = Unit::create(['name' => 'B', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $a->id,
        'to_unit_id' => $b->id,
        'factor' => 3,
    ]);

    $this->withToken(adminToken())
        ->getJson("/api/units/{$a->id}/conversions")
        ->assertOk()
        ->assertJsonPath('data.0.factor', '3.000000')
        ->assertJsonPath('data.0.formula', '1 A = 3.000000 B');
});

test('admin can create conversion from unit', function () {
    $a = Unit::create(['name' => 'A', 'dimension' => 'count', 'is_active' => true]);
    $b = Unit::create(['name' => 'B', 'dimension' => 'count', 'is_active' => true]);

    $this->withToken(adminToken())
        ->postJson("/api/units/{$a->id}/conversions", [
            'to_unit_id' => $b->id,
            'factor' => 10,
        ])
        ->assertCreated()
        ->assertJsonPath('data.factor', '10.000000');
});

test('admin cannot create inverse conversion when pair exists', function () {
    $a = Unit::create(['name' => 'A', 'dimension' => 'count', 'is_active' => true]);
    $b = Unit::create(['name' => 'B', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $a->id,
        'to_unit_id' => $b->id,
        'factor' => 10,
    ]);

    $this->withToken(adminToken())
        ->postJson("/api/units/{$b->id}/conversions", [
            'to_unit_id' => $a->id,
            'factor' => 0.1,
        ])
        ->assertUnprocessable();
});

test('admin cannot create conversion across dimensions', function () {
    $a = Unit::create(['name' => 'Metro', 'dimension' => 'length', 'is_active' => true]);
    $b = Unit::create(['name' => 'Kilo', 'dimension' => 'mass', 'is_active' => true]);

    $this->withToken(adminToken())
        ->postJson("/api/units/{$a->id}/conversions", [
            'to_unit_id' => $b->id,
            'factor' => 1,
        ])
        ->assertUnprocessable();
});

test('admin can convert using inverse stored row', function () {
    $doc = Unit::create(['name' => 'Docena', 'dimension' => 'count', 'is_active' => true]);
    $und = Unit::create(['name' => 'Unidad', 'dimension' => 'count', 'is_active' => true]);
    UnitConversion::create([
        'from_unit_id' => $doc->id,
        'to_unit_id' => $und->id,
        'factor' => 12,
    ]);

    $this->withToken(adminToken())
        ->postJson('/api/unit-conversions/convert', [
            'from_unit_id' => $und->id,
            'to_unit_id' => $doc->id,
            'quantity' => 24,
        ])
        ->assertOk()
        ->assertJsonPath('data.via', 'inverse')
        ->assertJsonPath('data.quantity_to', '2.00000000');
});

test('cashier cannot create unit', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');
    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->postJson('/api/units', [
            'name' => 'X',
            'dimension' => 'count',
            'is_active' => true,
        ])
        ->assertForbidden();
});
