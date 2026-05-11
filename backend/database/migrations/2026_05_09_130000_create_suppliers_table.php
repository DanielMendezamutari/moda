<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ruc', 32)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 64);
            $table->text('address');
            $table->boolean('is_active')->default(true);

            $table->string('contact_name')->nullable();
            $table->string('phone_alt', 64)->nullable();
            $table->string('website')->nullable();
            $table->string('city')->nullable();
            $table->string('department')->nullable();
            $table->string('country')->default('Bolivia');
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
