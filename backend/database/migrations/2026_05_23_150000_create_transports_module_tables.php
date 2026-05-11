<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas definitivas de transporte / traslado entre almacenes.
 * Omite si ya existe `transport_details` (instalaciones que aplicaron una migración previa experimental).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transport_details')) {
            return;
        }

        Schema::dropIfExists('transport_items');
        Schema::dropIfExists('transports');

        Schema::create('transports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_start_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('warehouse_end_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('date_emision')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('state', 40)->default('solicitud');
            $table->decimal('importe', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('date_entrega')->nullable();
            $table->string('reference', 128)->nullable();
            $table->timestamps();

            $table->index(['warehouse_start_id', 'state']);
            $table->index(['warehouse_end_id', 'state']);
        });

        Schema::create('transport_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transport_id')->constrained('transports')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('price_unit', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2)->nullable();
            $table->string('state', 32)->default('solicitud');
            $table->text('description')->nullable();
            $table->foreignId('user_salida_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_salida')->nullable();
            $table->foreignId('user_entrega_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_entrega')->nullable();
            $table->timestamp('inventory_departed_at')->nullable();
            $table->timestamp('inventory_arrived_at')->nullable();
            $table->decimal('transfer_unit_cost', 14, 4)->nullable()
                ->comment('Costo unitario (promedio origen) al salida; base para entrada destino');
            $table->decimal('transfer_value', 14, 4)->nullable()
                ->comment('Valor total salido (cantidad × transfer_unit_cost)');
            $table->timestamps();

            $table->index(['transport_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_details');
        Schema::dropIfExists('transports');
    }
};
