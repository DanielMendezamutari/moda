<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->foreignId('default_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
        });

        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference_amount', 12, 2)->nullable();
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cash_register_id', 'status']);
            $table->index(['opened_at', 'closed_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('source', 24)->default('manual');
            $table->decimal('amount', 12, 2);
            $table->string('method_payment', 64)->nullable();
            $table->text('description')->nullable();
            $table->timestamp('occurred_at');
            // FK added in 2026_05_18_120000_expand_sales_module (sale_payments is created later).
            $table->unsignedBigInteger('sale_payment_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['cash_register_session_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_register_session_id')->nullable()->after('user_id')->constrained('cash_register_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['cash_register_session_id']);
            $table->dropColumn('cash_register_session_id');
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
