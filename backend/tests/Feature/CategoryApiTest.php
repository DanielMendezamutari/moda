<?php

use App\Models\Category;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can list categories', function () {
    Category::create(['title' => 'Calzado', 'is_active' => true]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'title', 'image_url', 'is_active', 'created_at'],
            ],
        ]);
});

test('admin can create category without image', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->post('/api/categories', [
            'title' => 'Accesorios',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Accesorios');

    expect(Category::where('title', 'Accesorios')->exists())->toBeTrue();
});

test('admin can create category with image', function () {
    Storage::fake('public');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    /** PNG 1×1 válido sin depender de GD en el servidor de tests. */
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $tmp = tempnam(sys_get_temp_dir(), 'cat');
    file_put_contents($tmp, $png);
    $file = new UploadedFile($tmp, 'cat.png', 'image/png', null, true);

    $this->withToken($token)
        ->post('/api/categories', [
            'title' => 'Ropa',
            'is_active' => true,
            'image' => $file,
        ])
        ->assertCreated();

    $cat = Category::where('title', 'Ropa')->firstOrFail();
    expect($cat->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($cat->image_path);
});

test('cashier cannot create category', function () {
    $user = User::factory()->create();
    $user->assignRole('cashier');

    $token = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->post('/api/categories', [
            'title' => 'X',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('admin can update category', function () {
    $category = Category::create(['title' => 'Vestuario', 'is_active' => true]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->patchJson("/api/categories/{$category->id}", [
            'title' => 'Vestuario urbano',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Vestuario urbano')
        ->assertJsonPath('data.is_active', false);
});

test('admin can delete category', function () {
    Storage::fake('public');

    $category = Category::create([
        'title' => 'Temporal',
        'is_active' => true,
        'image_path' => 'categories/x.jpg',
    ]);
    Storage::disk('public')->put('categories/x.jpg', 'fake');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $token = $this->postJson('/api/auth/login', [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertOk()->json('access_token');

    $this->withToken($token)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertOk();

    expect(Category::find($category->id))->toBeNull();
});
