<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CashRegisterFlowService
{
    /**
     * Cajero: siempre debe vincular una sesión abierta.
     * Admin sin sucursal puede omitirla.
     * Usuario con sucursal (no cajero ya cubierto arriba): sesión obligatoria.
     */
    public function assertSessionForSale(User $user, ?int $sessionId): ?CashRegisterSession
    {
        if ($user->hasRole('cashier')) {
            if ($sessionId === null || $sessionId <= 0) {
                throw ValidationException::withMessages([
                    'cash_register_session_id' => ['Debés abrir una caja (turno) en el módulo Caja antes de vender.'],
                ]);
            }

            return $this->validateOpenSession($sessionId, $user);
        }

        if ($user->branch_id === null) {
            if ($sessionId === null || $sessionId <= 0) {
                return null;
            }

            return $this->validateOpenSession($sessionId, $user);
        }

        if ($sessionId === null || $sessionId <= 0) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => ['Debés abrir una caja (turno) en el módulo Caja antes de vender.'],
            ]);
        }

        return $this->validateOpenSession($sessionId, $user);
    }

    public function validateOpenSession(int $sessionId, User $user): CashRegisterSession
    {
        $session = CashRegisterSession::query()->with('cashRegister')->findOrFail($sessionId);

        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'cash_register_session_id' => ['La sesión de caja no está abierta.'],
            ]);
        }

        if ($user->branch_id !== null
            && (int) $session->cashRegister->branch_id !== (int) $user->branch_id) {
            throw ValidationException::withMessages([
                'cash_register_session_id' => ['Esa caja no pertenece a tu sucursal.'],
            ]);
        }

        return $session;
    }

    public function userCanAccessRegister(User $user, int $branchId): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->branch_id !== null && (int) $user->branch_id === $branchId;
    }
}
