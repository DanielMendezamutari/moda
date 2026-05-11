<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('units')->restrictOnDelete();
            /** 1 unidad "desde" equivale a factor unidades "hacia" (ej. 1 caja = 12 unidades → factor 12). */
            $table->decimal('factor', 18, 6);
            $table->timestamps();

            $table->unique(['from_unit_id', 'to_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
    }
};
