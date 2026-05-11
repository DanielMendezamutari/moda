<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        $view = Permission::findOrCreate('returns.view', $guard);
        $manage = Permission::findOrCreate('returns.manage', $guard);

        $admin = Role::where('name', 'admin')->where('guard_name', $guard)->first();
        if ($admin) {
            $admin->givePermissionTo([$view, $manage]);
        }

        $cashier = Role::where('name', 'cashier')->where('guard_name', $guard)->first();
        if ($cashier) {
            $cashier->givePermissionTo([$view, $manage]);
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';
        $names = ['returns.view', 'returns.manage'];

        $perms = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $names)
            ->get();

        foreach ($perms as $p) {
            $p->roles()->detach();
        }

        Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $names)
            ->delete();
    }
};
