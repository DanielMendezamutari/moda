<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Roles (`api`) + usuarios mínimos para usar el sistema sin datos de sucursales, almacenes ni catálogo.
 *
 * El proyecto solo define dos roles con permisos: `admin` y `cashier` (ver RolePermissionSeeder).
 *
 * Credenciales (contraseña para ambos: `password`):
 * - admin@moda.test — rol admin — PIN 4321
 * - cajero@moda.test — rol cashier — PIN 1234
 */
class BasicUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => 'admin@moda.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'pin' => '4321',
                'branch_id' => null,
                'document_number' => '10000001',
                'gender' => 'female',
                'is_active' => true,
                'avatar_preset' => 'avatar-5',
            ],
        );
        $admin->syncRoles(['admin']);

        $cashier = User::updateOrCreate(
            ['email' => 'cajero@moda.test'],
            [
                'name' => 'Cajero',
                'password' => 'password',
                'pin' => '1234',
                'branch_id' => null,
                'document_number' => '10000002',
                'gender' => 'male',
                'is_active' => true,
                'avatar_preset' => 'avatar-2',
            ],
        );
        $cashier->syncRoles(['cashier']);
    }
}
