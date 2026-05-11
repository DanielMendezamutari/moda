<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('product_warehouses', function (Blueprint $table) {
            $table->decimal('sale_price', 12, 2)->nullable()->after('umbral');
            $table->decimal('purchase_price', 12, 2)->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_warehouses', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'purchase_price']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
