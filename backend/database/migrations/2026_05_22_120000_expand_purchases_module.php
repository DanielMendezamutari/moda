<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->after('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('date_emision')->nullable()->after('user_id');
            $table->string('state', 32)->default('solicitud')->after('date_emision');
            $table->string('type_comprobant', 64)->nullable()->after('state');
            $table->string('n_comprobant', 128)->nullable()->after('type_comprobant');
            $table->text('description')->nullable()->after('n_comprobant');
            $table->decimal('importe', 14, 2)->default(0)->after('description');
            $table->decimal('igv', 14, 2)->default(0)->after('importe');
            $table->decimal('total', 14, 2)->default(0)->after('igv');
            $table->date('date_entrega')->nullable()->after('total');
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->decimal('line_total', 14, 2)->nullable()->after('unit_cost');
            $table->string('state', 32)->default('solicitud')->after('line_total');
            $table->foreignId('user_entrega_id')->nullable()->after('state')->constrained('users')->nullOnDelete();
            $table->timestamp('date_entrega')->nullable()->after('user_entrega_id');
            $table->text('description')->nullable()->after('date_entrega');
            $table->timestamp('inventory_received_at')->nullable()->after('description');
            $table->index(['purchase_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropIndex(['purchase_id', 'state']);
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['user_entrega_id']);
            $table->dropColumn([
                'unit_id', 'line_total', 'state', 'user_entrega_id', 'date_entrega', 'description', 'inventory_received_at',
            ]);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'warehouse_id', 'user_id', 'date_emision', 'state', 'type_comprobant', 'n_comprobant',
                'description', 'importe', 'igv', 'total', 'date_entrega',
            ]);
        });
    }
};
