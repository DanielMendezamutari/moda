<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Roles y permisos base para Moda POS (guard `api`, alineado con JWT).
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        $permissions = [
            'pos.sale.create',
            'pos.sale.view',
            'products.manage',
            'reports.view',
            'clients.view',
            'clients.manage',
            'returns.view',
            'returns.manage',
            'purchases.view',
            'purchases.manage',
            'inventory.kardex.view',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, $guard);
        }

        $admin = Role::findOrCreate('admin', $guard);
        $cashier = Role::findOrCreate('cashier', $guard);
        Role::findOrCreate('store_customer', $guard);

        $admin->syncPermissions(Permission::where('guard_name', $guard)->get());

        $cashier->syncPermissions([
            'pos.sale.create',
            'pos.sale.view',
            'clients.view',
            'returns.view',
            'returns.manage',
        ]);
    }
}
