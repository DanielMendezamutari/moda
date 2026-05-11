<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debe ejecutarse después de `create_products_table` y `create_warehouses_table` (FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'barcode')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('name');
            $table->string('barcode', 64)->nullable()->unique()->after('sku');
            $table->boolean('is_gift_card')->default(false)->after('price');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('is_gift_card');
            $table->boolean('is_active')->default(true)->after('discount_percent');
            $table->decimal('cost_price', 12, 2)->nullable()->after('is_active');
            $table->unsignedInteger('warranty_days')->default(0)->after('cost_price');
            $table->foreignId('category_id')->nullable()->after('warranty_days')->constrained('categories')->restrictOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('category_id')->constrained('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn([
                'image_path',
                'barcode',
                'is_gift_card',
                'discount_percent',
                'is_active',
                'cost_price',
                'warranty_days',
                'category_id',
                'warehouse_id',
            ]);
        });
    }
};
