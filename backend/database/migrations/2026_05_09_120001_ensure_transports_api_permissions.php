<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Los permisos se aseguran en una migración posterior (después de `create_permission_tables`).
 *
 * @see 2026_05_22_130002_ensure_transports_api_permissions
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
