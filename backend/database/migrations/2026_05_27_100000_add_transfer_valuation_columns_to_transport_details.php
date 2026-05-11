<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instalaciones que ya tenían `transport_details` antes de incluir columnas de valoración en la migración de creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_details')) {
            return;
        }

        Schema::table('transport_details', function (Blueprint $table): void {
            if (! Schema::hasColumn('transport_details', 'transfer_unit_cost')) {
                $table->decimal('transfer_unit_cost', 14, 4)->nullable()->after('inventory_arrived_at')
                    ->comment('Costo unitario (promedio origen) al salida; base para entrada destino');
            }
            if (! Schema::hasColumn('transport_details', 'transfer_value')) {
                $table->decimal('transfer_value', 14, 4)->nullable()->after('transfer_unit_cost')
                    ->comment('Valor total salido (cantidad × transfer_unit_cost)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transport_details')) {
            return;
        }

        Schema::table('transport_details', function (Blueprint $table): void {
            if (Schema::hasColumn('transport_details', 'transfer_value')) {
                $table->dropColumn('transfer_value');
            }
            if (Schema::hasColumn('transport_details', 'transfer_unit_cost')) {
                $table->dropColumn('transfer_unit_cost');
            }
        });
    }
};
