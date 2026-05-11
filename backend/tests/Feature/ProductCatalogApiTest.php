<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('admin can create product with generated barcode', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->postJson('/api/products', [
            'sku' => 'PROD-GEN-1',
            'name' => 'Vestido demo',
            'price' => 199.99,
            'is_active' => true,
            'is_gift_card' => false,
            'generate_barcode' => true,
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'sku', 'barcode', 'name', 'price', 'is_gift_card', 'is_active'],
        ]);

    $p = Product::where('sku', 'PROD-GEN-1')->firstOrFail();

    expect($p->barcode)->not->toBeNull()
        ->and(strlen($p->barcode))->toBe(13);
});

test('admin can create product with generated sku', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $res = $this->actingAs($admin, 'api')
        ->postJson('/api/products', [
            'generate_sku' => true,
            'name' => 'Pollera demo',
            'price' => 120.5,
            'wholesale_price' => 95,
            'is_active' => true,
            'is_gift_card' => false,
        ])
        ->assertCreated()
        ->json('data');

    expect($res['sku'])->toStartWith('PRD-')
        ->and(strlen($res['sku']))->toBeGreaterThan(4)
        ->and($res['wholesale_price'])->toBe('95.00');
});

test('cashier does not see inactive products in list', function () {
    Product::factory()->create(['name' => 'Activo', 'is_active' => true]);
    Product::factory()->create(['name' => 'Oculto', 'is_active' => false]);

    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $names = $this->actingAs($cashier, 'api')
        ->getJson('/api/products')
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toContain('Activo')->not->toContain('Oculto');
});

test('admin can export products as csv', function () {
    Product::factory()->create(['sku' => 'EXP-SKU-1', 'name' => 'Producto export']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin, 'api')
        ->get('/api/products/export?format=csv')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');

    $body = $response->streamedContent();

    expect($body)->toContain('SKU')->toContain('EXP-SKU-1')->toContain('Producto export');
});

test('export rejects invalid format', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/products/export?format=xml')
        ->assertStatus(422);
});

test('admin can download product report pdf', function () {
    Product::factory()->create(['sku' => 'PDF-1', 'name' => 'Para informe PDF', 'barcode' => 'SKU-BAR-TEST']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/products/export/pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->actingAs($admin, 'api')
        ->get('/api/products/export/pdf?variant=list')
        ->assertOk();
});

test('product report pdf rejects invalid variant', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin, 'api')
        ->get('/api/products/export/pdf?variant=xyz')
        ->assertStatus(422);
});

test('admin can download product import template as csv', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $body = $this->actingAs($admin, 'api')
        ->get('/api/products/import/template?format=csv')
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain('SKU')->toContain('Nombre')->toContain('Precio final');
});

test('admin xlsx import template includes categorias and almacenes sheets', function () {
    $this->seed(BranchSeeder::class);

    Category::query()->create(['title' => 'CatPlantillaXlsx', 'is_active' => true]);
    Warehouse::query()->create([
        'name' => 'AlmPlantilla',
        'address' => 'Dir',
        'branch_id' => Branch::query()->firstOrFail()->id,
        'state' => 'LP',
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $binary = $this->actingAs($admin, 'api')
        ->get('/api/products/import/template?format=xlsx')
        ->assertOk()
        ->getContent();

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx-tpl');
    expect($tmp)->not->toBeFalse();
    file_put_contents($tmp, $binary);

    $zip = new ZipArchive;
    expect($zip->open($tmp))->toBeTrue();
    $workbook = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    @unlink($tmp);

    expect($workbook)->toBeString()
        ->and($workbook)->toContain('Categorías')
        ->and($workbook)->toContain('Almacenes')
        ->and($workbook)->toContain('Importar');
});

test('admin can import products from csv', function () {
    $this->seed(BranchSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Category::query()->create(['title' => 'RopaTestImp', 'is_active' => true]);
    $wh = Warehouse::query()->create([
        'name' => 'Alm imp',
        'address' => 'X',
        'branch_id' => Branch::query()->firstOrFail()->id,
        'state' => 'LP',
    ]);

    $line = 'IMP-CSV-1,Producto importado,,RopaTestImp,10.50,,,0,'.$wh->id.',4,2,Sí,,No,No,0';
    $csv = "\xEF\xBB\xBF"."SKU,Nombre,Descripción,Categoría,Precio final,Precio mayoreo,Precio costo,Dto. %,ID almacén,Stock,Umbral,Activo,Código barras,Generar código barras,Gift card,Garantía (días)\n".$line;

    $file = UploadedFile::fake()->createWithContent('productos.csv', $csv);

    $this->actingAs($admin, 'api')
        ->post('/api/products/import', ['file' => $file])
        ->assertOk()
        ->assertJson([
            'imported' => 1,
            'failed' => 0,
        ]);

    $p = Product::query()->where('sku', 'IMP-CSV-1')->firstOrFail();
    expect($p->name)->toBe('Producto importado')
        ->and((float) $p->price)->toBe(10.5)
        ->and($p->warehouses)->toHaveCount(1)
        ->and((int) $p->warehouses->first()->pivot->stock)->toBe(4);
});

test('cashier cannot import products', function () {
    $this->seed(BranchSeeder::class);

    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $csv = "\xEF\xBB\xBF"."SKU,Nombre,Descripción,Categoría,Precio final,Precio mayoreo,Precio costo,Dto. %,ID almacén,Stock,Umbral,Activo,Código barras,Generar código barras,Gift card,Garantía (días)\nX,Y,,,1,,,0,,0,0,Sí,,No,No,0";
    $file = UploadedFile::fake()->createWithContent('x.csv', $csv);

    $this->actingAs($cashier, 'api')
        ->post('/api/products/import', ['file' => $file])
        ->assertForbidden();
});
