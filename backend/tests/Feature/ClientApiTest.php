<?php

use App\Models\Branch;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can create client with credit fields', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->postJson('/api/clients', [
            'name' => 'Ana',
            'surname' => 'Pérez',
            'phone' => '70000001',
            'email' => 'ana@test.test',
            'type_client' => 'natural',
            'type_document' => 'CI',
            'n_document' => '1234567',
            'branch_id' => $branch->id,
            'is_active' => true,
            'credit_enabled' => true,
            'credit_limit' => 5000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Ana Pérez')
        ->assertJsonPath('data.credit_balance', '0.00');

    expect(Client::query()->where('n_document', '1234567')->exists())->toBeTrue();
});

test('admin can export clients as csv', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Client::factory()->create([
        'branch_id' => $branch->id,
        'name' => 'Export',
        'surname' => 'Test',
        'n_document' => 'EXP-001',
    ]);

    $res = $this->actingAs($admin, 'api')
        ->get('/api/clients/export?format=csv');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    $body = $res->streamedContent();
    expect($body)->toContain('Nombre completo')->toContain('EXP-001')->toContain('Export Test');
});

test('clients export rejects invalid format', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/clients/export?format=xml')
        ->assertStatus(422);
});

test('admin can download clients report pdf', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/clients/export/pdf?variant=full')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($admin, 'api')
        ->get('/api/clients/export/pdf?variant=debtors')
        ->assertOk();
});

test('clients pdf rejects invalid variant', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/clients/export/pdf?variant=xyz')
        ->assertStatus(422);
});

test('admin can post credit charge and payment', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => true,
        'credit_limit' => 1000,
        'credit_balance' => 0,
    ]);

    $this->actingAs($admin, 'api')
        ->postJson("/api/clients/{$client->id}/credit-charge", [
            'amount' => 200,
            'description' => 'Venta fiado',
        ])
        ->assertCreated()
        ->assertJsonPath('data.client.credit_balance', '200.00');

    $this->actingAs($admin, 'api')
        ->postJson("/api/clients/{$client->id}/credit-payment", [
            'amount' => 50,
            'description' => 'Abono',
        ])
        ->assertCreated()
        ->assertJsonPath('data.client.credit_balance', '150.00');
});

test('credit charge rejects when over limit', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => true,
        'credit_limit' => 100,
        'credit_balance' => 80,
    ]);

    $this->actingAs($admin, 'api')
        ->postJson("/api/clients/{$client->id}/credit-charge", [
            'amount' => 50,
        ])
        ->assertStatus(422);
});

test('cannot delete client with positive credit balance', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_balance' => 10,
        'credit_enabled' => false,
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client']);
});

test('cannot delete client while credit is still enabled', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => true,
        'credit_balance' => 0,
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(422);
});

test('cannot delete client with open sale debt', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => false,
        'credit_balance' => 0,
        'user_id' => null,
    ]);

    Sale::factory()->create([
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'subtotal' => 100,
        'igv' => 0,
        'total' => 100,
        'paid_out' => 50,
        'debt' => 50,
        'state_sale' => 'validated',
        'state_payment' => 'partial',
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client']);
});

test('cannot delete client linked to system user', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $linked = User::factory()->create(['branch_id' => $branch->id]);

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'user_id' => $linked->id,
        'credit_enabled' => false,
        'credit_balance' => 0,
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/clients/{$client->id}")
        ->assertStatus(422);
});

test('deletion status returns metrics and blockers', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => true,
        'credit_balance' => '0',
    ]);

    $this->actingAs($admin, 'api')
        ->getJson("/api/clients/{$client->id}/deletion-status")
        ->assertOk()
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath('data.metrics.credit_enabled', true);
});

test('admin can delete client when validation passes', function () {
    $branch = Branch::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $client = Client::factory()->create([
        'branch_id' => $branch->id,
        'credit_enabled' => false,
        'credit_balance' => 0,
        'user_id' => null,
    ]);

    $this->actingAs($admin, 'api')
        ->deleteJson("/api/clients/{$client->id}")
        ->assertOk();

    expect(Client::query()->whereKey($client->id)->exists())->toBeFalse();
});

test('cashier can list clients but not create', function () {
    $branch = Branch::factory()->create();
    $cashier = User::factory()->create(['branch_id' => $branch->id]);
    $cashier->assignRole('cashier');

    Client::factory()->create(['branch_id' => $branch->id, 'n_document' => '111']);

    $this->actingAs($cashier, 'api')
        ->getJson('/api/clients')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($cashier, 'api')
        ->postJson('/api/clients', [
            'name' => 'X',
            'type_client' => 'natural',
            'type_document' => 'CI',
            'n_document' => '999',
            'branch_id' => $branch->id,
            'is_active' => true,
        ])
        ->assertForbidden();
});
