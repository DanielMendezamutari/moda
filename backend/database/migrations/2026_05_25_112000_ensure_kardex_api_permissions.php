<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $guard = 'api';

        Permission::findOrCreate('inventory.kardex.view', $guard);

        $admin = Role::query()->where('name', 'admin')->where('guard_name', $guard)->first();
        if ($admin) {
            $admin->givePermissionTo('inventory.kardex.view');
        }
    }

    public function down(): void
    {
        $guard = 'api';

        Permission::query()->where('name', 'inventory.kardex.view')->where('guard_name', $guard)->delete();
    }
};
