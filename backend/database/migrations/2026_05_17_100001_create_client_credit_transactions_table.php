<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24); // charge | payment | adjustment
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('description')->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credit_transactions');
    }
};
