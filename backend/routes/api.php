<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\CashRegisterSessionController;
use App\Http\Controllers\Api\CashReportController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConversionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryKardexController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductReturnController;
use App\Http\Controllers\Api\PublicCatalogController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\StoreAuthController;
use App\Http\Controllers\Api\StoreCartController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TransportController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UnitConversionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login-pin', [AuthController::class, 'loginWithPin']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::post('/refresh', [AuthController::class, 'refresh']);
});

Route::get('public/catalog/categories', [PublicCatalogController::class, 'categories']);
Route::get('public/catalog/products', [PublicCatalogController::class, 'products']);
Route::post('store/register', [StoreAuthController::class, 'register']);
Route::post('store/login', [StoreAuthController::class, 'login']);

Route::middleware('auth:api')->group(function (): void {
    Route::get('meta/bolivia-departments', [MetaController::class, 'boliviaDepartments']);
    Route::get('meta/unit-dimensions', [MetaController::class, 'unitDimensions']);

    Route::post('store/cart/merge', [StoreCartController::class, 'merge']);
    Route::get('store/cart', [StoreCartController::class, 'index']);
    Route::post('store/cart/items', [StoreCartController::class, 'store']);
    Route::patch('store/cart/items/{product}', [StoreCartController::class, 'update']);
    Route::delete('store/cart/items/{product}', [StoreCartController::class, 'destroy']);

    Route::get('reports', [ReportController::class, 'index']);
    Route::get('dashboard/analytics', [DashboardController::class, 'analytics']);

    Route::get('permissions', [PermissionController::class, 'index']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::match(['patch', 'put'], 'roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::get('clients/export', [ClientController::class, 'export']);
    Route::get('clients/export/pdf', [ClientController::class, 'exportReportPdf']);
    Route::post('clients', [ClientController::class, 'store']);
    Route::get('clients/{client}/deletion-status', [ClientController::class, 'deletionStatus']);
    Route::get('clients/{client}', [ClientController::class, 'show']);
    Route::match(['patch', 'put'], 'clients/{client}', [ClientController::class, 'update']);
    Route::delete('clients/{client}', [ClientController::class, 'destroy']);
    Route::get('clients/{client}/credit-movements', [ClientController::class, 'creditMovements']);
    Route::post('clients/{client}/credit-charge', [ClientController::class, 'creditCharge']);
    Route::post('clients/{client}/credit-payment', [ClientController::class, 'creditPayment']);
    Route::post('clients/{client}/credit-adjustment', [ClientController::class, 'creditAdjustment']);

    Route::get('branches', [BranchController::class, 'index']);
    Route::post('branches', [BranchController::class, 'store']);
    Route::match(['patch', 'put'], 'branches/{branch}', [BranchController::class, 'update']);
    Route::delete('branches/{branch}', [BranchController::class, 'destroy']);

    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store']);
    Route::match(['patch', 'put'], 'warehouses/{warehouse}', [WarehouseController::class, 'update']);
    Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    /** POST admite FormData multipart (PHP no rellena bien PATCH + multipart). */
    Route::match(['patch', 'post'], 'users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);

    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);
    Route::match(['patch', 'put', 'post'], 'categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

    Route::get('suppliers', [SupplierController::class, 'index']);
    Route::post('suppliers', [SupplierController::class, 'store']);
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show']);
    Route::match(['patch', 'put'], 'suppliers/{supplier}', [SupplierController::class, 'update']);
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);

    Route::get('units', [UnitController::class, 'index']);
    Route::post('units', [UnitController::class, 'store']);
    Route::get('units/{unit}', [UnitController::class, 'show']);
    Route::match(['patch', 'put'], 'units/{unit}', [UnitController::class, 'update']);
    Route::delete('units/{unit}', [UnitController::class, 'destroy']);

    Route::get('units/{unit}/conversions', [UnitConversionController::class, 'index']);
    Route::post('units/{unit}/conversions', [UnitConversionController::class, 'store']);
    Route::post('unit-conversions/convert', [UnitConversionController::class, 'convert']);
    Route::match(['patch', 'put'], 'unit-conversions/{unit_conversion}', [UnitConversionController::class, 'update']);
    Route::delete('unit-conversions/{unit_conversion}', [UnitConversionController::class, 'destroy']);

    Route::get('products/export/pdf', [ProductController::class, 'exportReportPdf']);
    Route::get('products/export', [ProductController::class, 'export']);
    Route::get('products/import/template', [ProductController::class, 'importTemplate']);
    Route::post('products/import', [ProductController::class, 'importStore']);
    Route::apiResource('products', ProductController::class);
    /** POST + multipart: PHP suele no rellenar bien PATCH con archivos. */
    Route::post('products/{product}', [ProductController::class, 'update']);
    Route::get('sales/export/pdf', [SaleController::class, 'exportReportPdf']);
    Route::get('sales/export', [SaleController::class, 'export']);
    Route::apiResource('product-returns', ProductReturnController::class);
    Route::get('purchases/export/pdf', [PurchaseController::class, 'exportReportPdf']);
    Route::get('purchases/export', [PurchaseController::class, 'export']);
    Route::match(['patch', 'put'], 'purchases/{purchase}/items/{purchaseItem}', [PurchaseController::class, 'updateItem']);
    Route::get('purchases/{purchase}/pdf', [PurchaseController::class, 'pdf']);
    Route::apiResource('purchases', PurchaseController::class);

    Route::get('transports/export/pdf', [TransportController::class, 'exportReportPdf']);
    Route::get('transports/export', [TransportController::class, 'export']);
    Route::match(['patch', 'put'], 'transports/{transport}/details/{transportDetail}', [TransportController::class, 'updateDetail']);
    Route::get('transports/{transport}/pdf', [TransportController::class, 'pdf']);
    Route::apiResource('transports', TransportController::class);

    Route::apiResource('conversions', ConversionController::class)->only(['index', 'store', 'show']);

    Route::get('inventory/kardex', [InventoryKardexController::class, 'index']);
    Route::get('inventory/kardex/product-ledger', [InventoryKardexController::class, 'productLedger']);
    Route::get('sales/{sale}/ticket', [SaleController::class, 'ticket']);
    Route::post('sales/{sale}/payments', [SaleController::class, 'storePayment']);
    Route::apiResource('sales', SaleController::class);

    Route::get('cash-registers', [CashRegisterController::class, 'index']);
    Route::post('cash-registers', [CashRegisterController::class, 'store']);
    Route::match(['patch', 'put'], 'cash-registers/{cashRegister}', [CashRegisterController::class, 'update']);

    Route::get('cash-register-sessions', [CashRegisterSessionController::class, 'index']);
    Route::get('cash-register-sessions/active', [CashRegisterSessionController::class, 'active']);
    Route::post('cash-register-sessions/open', [CashRegisterSessionController::class, 'open']);
    Route::get('cash-register-sessions/{cashRegisterSession}/summary', [CashRegisterSessionController::class, 'summary']);
    Route::get('cash-register-sessions/{cashRegisterSession}/close-report', [CashRegisterSessionController::class, 'closeReport']);
    Route::post('cash-register-sessions/{cashRegisterSession}/close', [CashRegisterSessionController::class, 'close']);

    Route::get('cash-movements/{cashMovement}/ticket', [CashMovementController::class, 'ticket']);
    Route::get('cash-movements', [CashMovementController::class, 'index']);
    Route::post('cash-movements', [CashMovementController::class, 'store']);

    Route::get('cash-reports/sessions', [CashReportController::class, 'sessions']);
    Route::get('cash-reports/movements', [CashReportController::class, 'movements']);
    Route::get('cash-reports/sales-by-day', [CashReportController::class, 'salesByDay']);
});
