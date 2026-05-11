<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname')->nullable();
            $table->string('full_name');
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('type_client', 32)->default('natural'); // natural | juridico
            $table->string('type_document', 32)->default('CI'); // CI, NIT, CE, PASAPORTE, OTRO
            $table->string('n_document', 64);
            $table->date('birthdate')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('gender', 16)->nullable(); // M | F | otro
            $table->string('ubigeo', 32)->nullable();
            $table->text('address')->nullable();
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->decimal('credit_balance', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'n_document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
