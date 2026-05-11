<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('login with email and password returns jwt', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
        ])
        ->assertJson(['token_type' => 'bearer']);
});

test('login rejects invalid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422);
});

test('login rejects inactive user', function () {
    $user = User::factory()->create([
        'is_active' => false,
    ]);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('login with pin rejects inactive user', function () {
    $user = User::factory()->create([
        'pin' => '847291',
        'is_active' => false,
    ]);

    $this->postJson('/api/auth/login-pin', [
        'pin' => '847291',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('login with pin only returns jwt', function () {
    $user = User::factory()->create([
        'pin' => '847291',
    ]);

    $response = $this->postJson('/api/auth/login-pin', [
        'pin' => '847291',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
        ]);
});

test('login with pin rejects wrong pin', function () {
    User::factory()->create([
        'pin' => '11112222',
    ]);

    $this->postJson('/api/auth/login-pin', [
        'pin' => '99999999',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('login with pin rejects ambiguous duplicate pins', function () {
    foreach (['a@t.test', 'b@t.test'] as $email) {
        User::factory()->create([
            'email' => $email,
            'pin' => '3333',
        ]);
    }

    $this->postJson('/api/auth/login-pin', [
        'pin' => '3333',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['pin']);
});

test('login accepts pin with 10 digits', function () {
    $user = User::factory()->create([
        'pin' => '1234567890',
    ]);

    $this->postJson('/api/auth/login-pin', [
        'pin' => '1234567890',
    ])->assertOk();
});

test('login rejects pin shorter than 4 or longer than 16 digits', function () {
    User::factory()->create(['pin' => '1234567890']);

    $this->postJson('/api/auth/login-pin', [
        'pin' => '123',
    ])->assertStatus(422);

    $this->postJson('/api/auth/login-pin', [
        'pin' => str_repeat('1', 17),
    ])->assertStatus(422);
});

test('me returns authenticated user', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('access_token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonFragment([
            'email' => $user->email,
            'name' => $user->name,
        ]);
});

test('me returns pos capability flags for admin role', function () {
    $this->seed(\Database\Seeders\RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('access_token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('is_admin', true)
        ->assertJsonPath('can_pos_sale_create', true)
        ->assertJsonPath('can_pos_sale_view', true)
        ->assertJsonPath('can_inventory_kardex_view', true);
});

test('me grants pos flags for admin role without synced permissions', function () {
    Role::findOrCreate('admin', 'api');

    $user = User::factory()->create();
    $user->assignRole('admin');

    expect($user->can('pos.sale.create'))->toBeFalse();

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('access_token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('is_admin', true)
        ->assertJsonPath('can_pos_sale_create', true)
        ->assertJsonPath('can_pos_sale_view', true)
        ->assertJsonPath('can_inventory_kardex_view', true);
});

test('refresh returns a new token', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('access_token');

    $refresh = $this->withToken($token)->postJson('/api/auth/refresh');

    $refresh->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

    expect($refresh->json('access_token'))->not->toBe($token);
});

test('logout succeeds with valid token', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->json('access_token');

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertOk();
});
