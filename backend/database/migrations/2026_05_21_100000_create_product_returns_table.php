<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->foreignId('sale_detail_id')->constrained('sale_details')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('state', 32);
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('resolution_date')->nullable();
            $table->text('description_resolution')->nullable();
            /** Cuando `state` = reparado se ingresa stock una vez; sirve para no duplicar ni revertir mal. */
            $table->timestamp('inventory_applied_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'created_at']);
            $table->index(['sale_detail_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_returns');
    }
};
