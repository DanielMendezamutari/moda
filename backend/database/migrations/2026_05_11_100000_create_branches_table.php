<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $database = Schema::getConnection()->getDatabaseName();
            $refs = DB::select(
                'SELECT DISTINCT TABLE_NAME AS t FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?',
                [$database, 'branches']
            );
            if (count($refs) > 0) {
                $names = collect($refs)->pluck('t')->implode(', ');
                throw new \RuntimeException(
                    "No se puede eliminar la tabla `branches`: siguen claves foráneas desde: {$names}. Ejecute migrate:rollback en lotes más recientes primero (caja, clientes, almacenes, etc.)."
                );
            }
        }

        Schema::dropIfExists('branches');
    }
};
