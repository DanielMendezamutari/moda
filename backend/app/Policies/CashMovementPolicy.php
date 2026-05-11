<?php

namespace App\Policies;

use App\Models\CashMovement;
use App\Models\User;

class CashMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('cashier');
    }

    public function view(User $user, CashMovement $movement): bool
    {
        if (! $user->hasRole('admin') && ! $user->hasRole('cashier')) {
            return false;
        }

        $movement->loadMissing('session.cashRegister');

        if ($user->hasRole('admin')) {
            return true;
        }

        $bid = $user->branch_id;

        return $bid !== null
            && $movement->session?->cashRegister
            && (int) $movement->session->cashRegister->branch_id === (int) $bid;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('cashier');
    }
}
