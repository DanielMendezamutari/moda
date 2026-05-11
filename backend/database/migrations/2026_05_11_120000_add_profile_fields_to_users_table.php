<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('document_number')->nullable()->unique()->after('email');
            $table->string('gender', 16)->nullable()->after('document_number');
            $table->boolean('is_active')->default(true)->after('gender');
            $table->string('avatar_upload_path')->nullable()->after('is_active');
            $table->string('avatar_preset', 32)->nullable()->after('avatar_upload_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'document_number',
                'gender',
                'is_active',
                'avatar_upload_path',
                'avatar_preset',
            ]);
        });
    }
};
