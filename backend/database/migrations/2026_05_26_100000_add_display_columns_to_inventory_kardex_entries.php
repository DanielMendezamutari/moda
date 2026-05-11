<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_kardex_entries', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('warehouse_id')->constrained('units')->nullOnDelete();
            $table->string('detail_label', 64)->nullable()->after('movement_type');
            $table->decimal('in_qty', 14, 4)->nullable()->after('total_value_delta');
            $table->decimal('in_unit_value', 14, 4)->nullable();
            $table->decimal('in_total_value', 14, 4)->nullable();
            $table->decimal('out_qty', 14, 4)->nullable();
            $table->decimal('out_unit_value', 14, 4)->nullable();
            $table->decimal('out_total_value', 14, 4)->nullable();
            $table->decimal('balance_total_value', 14, 4)->nullable()->after('balance_avg_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_kardex_entries', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'unit_id',
                'detail_label',
                'in_qty',
                'in_unit_value',
                'in_total_value',
                'out_qty',
                'out_unit_value',
                'out_total_value',
                'balance_total_value',
            ]);
        });
    }
};
