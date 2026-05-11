<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->text('address')->nullable()->after('code');
            $table->string('state', 128)->nullable()->after('address');
            $table->boolean('is_active')->default(true)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['address', 'state', 'is_active']);
        });
    }
};
