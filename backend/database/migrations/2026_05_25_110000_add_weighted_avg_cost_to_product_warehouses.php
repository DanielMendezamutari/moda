<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_warehouses', function (Blueprint $table) {
            $table->decimal('weighted_avg_cost', 14, 4)
                ->nullable()
                ->after('purchase_price')
                ->comment('Costo promedio ponderado por unidad de stock (pivot.unit_id); kardex / valoración');
        });
    }

    public function down(): void
    {
        Schema::table('product_warehouses', function (Blueprint $table) {
            $table->dropColumn('weighted_avg_cost');
        });
    }
};
