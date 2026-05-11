<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo y costo promedio unitario inicial por producto–almacén–unidad (apertura / corte para kardex y promedio ponderado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_initials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->unsignedInteger('stock')->default(0);
            $table->decimal('price_unit_avg', 14, 4)->nullable()->comment('Costo o precio unitario promedio ponderado inicial en la unidad indicada');
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_initials');
    }
};
