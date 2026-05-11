<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_start_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('unit_end_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('quantity_start', 14, 4);
            $table->decimal('quantity_end', 14, 4);
            $table->decimal('stock_delta', 14, 4)->comment('Cambio neto en unidad de stock del pivot (product_warehouses.unit_id)');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
