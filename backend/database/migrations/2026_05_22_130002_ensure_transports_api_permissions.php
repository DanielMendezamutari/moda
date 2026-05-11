<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'api';

        foreach (['transports.view', 'transports.manage'] as $name) {
            Permission::findOrCreate($name, $guard);
        }

        $admin = Role::query()->where('name', 'admin')->where('guard_name', $guard)->first();
        if ($admin) {
            $admin->givePermissionTo([
                'transports.view',
                'transports.manage',
            ]);
        }
    }

    public function down(): void
    {
        $guard = 'api';

        foreach (['transports.view', 'transports.manage'] as $name) {
            Permission::query()->where('name', $name)->where('guard_name', $guard)->delete();
        }
    }
};
