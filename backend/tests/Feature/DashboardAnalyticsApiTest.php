<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('dashboard analytics returns structure for admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->getJson('/api/dashboard/analytics')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'sales_by_day',
                'top_products',
                'range_days',
            ],
        ])
        ->assertJsonPath('data.range_days', 14);
});

test('dashboard analytics is forbidden without sale view permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson('/api/dashboard/analytics')
        ->assertForbidden();
});
