<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Alineación con modelo de datos Venta–Cotización: líneas en `sale_details`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_items') && ! Schema::hasTable('sale_details')) {
            Schema::rename('sale_items', 'sale_details');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_details') && ! Schema::hasTable('sale_items')) {
            Schema::rename('sale_details', 'sale_items');
        }
    }
};
