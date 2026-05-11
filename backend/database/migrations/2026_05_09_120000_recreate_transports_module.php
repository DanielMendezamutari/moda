<?php

use Illuminate\Database\Migrations\Migration;

/**
 * La creación del módulo de transporte se hace en {@see 2026_05_23_150000_create_transports_module_tables}
 * (después de almacenes, productos y compras), para no colisionar con el stub histórico en otras migraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
