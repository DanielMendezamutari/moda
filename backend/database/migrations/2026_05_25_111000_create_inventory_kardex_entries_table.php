<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_kardex_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('movement_type', 32);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('quantity_delta');
            $table->decimal('unit_cost', 14, 4)->nullable()->comment('Costo unitario aplicado al movimiento (salida = promedio antes)');
            $table->decimal('total_value_delta', 14, 4)->nullable()->comment('Impacto en valor de inventario (+ ingreso, - salida)');
            $table->unsignedInteger('balance_quantity');
            $table->decimal('balance_avg_cost', 14, 4)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // MySQL límite 64 caracteres en nombres de índice; el nombre auto-generado supera ese límite.
            $table->index(['product_id', 'warehouse_id', 'occurred_at'], 'idx_ike_prod_wh_occ');
            $table->index(['warehouse_id', 'occurred_at'], 'idx_ike_wh_occ');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_kardex_entries');
    }
};
