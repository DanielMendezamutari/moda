<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios listos para probar login POS (frontend + backend).
 *
 * Cajero: cajero@moda.test · contraseña "password" · PIN 1234
 * Admin:  admin@moda.test  · contraseña "password" · PIN 4321
 */
class PosDemoLoginSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = Branch::where('code', 'PRIN')->value('id');

        $cashier = User::updateOrCreate(
            ['email' => 'cajero@moda.test'],
            [
                'name' => 'Cajero Demo POS',
                'password' => 'password',
                'pin' => '1234',
                'branch_id' => $branchId,
                'document_number' => '90000001',
                'gender' => 'male',
                'is_active' => true,
                'avatar_preset' => 'avatar-2',
            ]
        );
        $cashier->syncRoles(['cashier']);

        $admin = User::updateOrCreate(
            ['email' => 'admin@moda.test'],
            [
                'name' => 'Administrador Demo',
                'password' => 'password',
                'pin' => '4321',
                'branch_id' => $branchId,
                'document_number' => '90000002',
                'gender' => 'female',
                'is_active' => true,
                'avatar_preset' => 'avatar-5',
            ]
        );
        $admin->syncRoles(['admin']);
    }
}
