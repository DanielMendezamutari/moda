<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock y umbral por producto y almacén (equivalente a product_warehouses del modelo de dominio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('umbral')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });

        $now = now();

        foreach (DB::table('products')->whereNotNull('warehouse_id')->cursor() as $r) {
            DB::table('product_warehouses')->insert([
                'product_id' => $r->id,
                'warehouse_id' => $r->warehouse_id,
                'unit_id' => null,
                'stock' => (int) $r->stock,
                'umbral' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $firstWh = DB::table('warehouses')->orderBy('id')->value('id');
        if ($firstWh !== null) {
            foreach (DB::table('products')->whereNull('warehouse_id')->where('stock', '>', 0)->cursor() as $r) {
                DB::table('product_warehouses')->insert([
                    'product_id' => $r->id,
                    'warehouse_id' => $firstWh,
                    'unit_id' => null,
                    'stock' => (int) $r->stock,
                    'umbral' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['stock', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0);
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
        });

        $seen = [];
        foreach (DB::table('product_warehouses')->orderBy('id')->cursor() as $pw) {
            $pid = (int) $pw->product_id;
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            DB::table('products')->where('id', $pid)->update([
                'warehouse_id' => $pw->warehouse_id,
                'stock' => (int) $pw->stock,
            ]);
        }

        Schema::dropIfExists('product_warehouses');
    }
};
