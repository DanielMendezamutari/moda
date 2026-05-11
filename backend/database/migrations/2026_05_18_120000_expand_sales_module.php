<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de líneas de venta: en el orden por defecto sigue siendo `sale_items` hasta el rename;
     * si solo existe `sale_details` (orden de migraciones alterado), se usa esa.
     */
    private function saleLinesTable(): ?string
    {
        if (Schema::hasTable('sale_items')) {
            return 'sale_items';
        }
        if (Schema::hasTable('sale_details')) {
            return 'sale_details';
        }

        return null;
    }

    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('type_client', 32)->nullable()->after('client_id');
            $table->decimal('subtotal', 12, 2)->default(0)->after('reference');
            $table->decimal('igv', 12, 2)->default(0)->after('subtotal');
            $table->string('state_sale', 32)->default('validated')->after('total');
            $table->string('state_payment', 32)->default('pending')->after('state_sale');
            $table->decimal('debt', 12, 2)->default(0)->after('state_payment');
            $table->decimal('paid_out', 12, 2)->default(0)->after('debt');
            $table->timestamp('date_validation')->nullable()->after('paid_out');
            $table->timestamp('date_pay_complete')->nullable()->after('date_validation');
            $table->text('description')->nullable()->after('date_pay_complete');
        });

        $lines = $this->saleLinesTable();
        if ($lines === null) {
            throw new \RuntimeException(
                'No existe sale_items ni sale_details. Debe ejecutarse antes 2026_05_16_100000_create_sale_purchase_transport_item_tables.'
            );
        }

        Schema::table($lines, function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->after('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->after('warehouse_id')->constrained('categories')->nullOnDelete();
            $table->decimal('discount', 12, 2)->default(0)->after('unit_price');
            $table->decimal('line_subtotal', 12, 2)->nullable()->after('discount');
            $table->decimal('line_total', 12, 2)->nullable()->after('line_subtotal');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method_payment', 64);
            $table->decimal('amount', 12, 2);
            $table->string('n_transaction')->nullable();
            $table->timestamps();

            $table->index(['sale_id', 'created_at']);
        });

        if (Schema::hasTable('cash_movements')) {
            Schema::table('cash_movements', function (Blueprint $table) {
                $table->foreign('sale_payment_id')
                    ->references('id')
                    ->on('sale_payments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_movements')) {
            Schema::table('cash_movements', function (Blueprint $table) {
                $table->dropForeign(['sale_payment_id']);
            });
        }

        Schema::dropIfExists('sale_payments');

        $lines = $this->saleLinesTable();
        if ($lines !== null) {
            Schema::table($lines, function (Blueprint $table) {
                $table->dropForeign(['unit_id']);
                $table->dropForeign(['warehouse_id']);
                $table->dropForeign(['category_id']);
                $table->dropColumn(['unit_id', 'warehouse_id', 'category_id', 'discount', 'line_subtotal', 'line_total']);
            });
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn([
                'client_id', 'type_client', 'subtotal', 'igv', 'state_sale', 'state_payment',
                'debt', 'paid_out', 'date_validation', 'date_pay_complete', 'description',
            ]);
        });
    }
};
