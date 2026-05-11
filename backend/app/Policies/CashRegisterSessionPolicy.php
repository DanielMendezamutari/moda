<?php

namespace App\Policies;

use App\Models\CashRegisterSession;
use App\Models\User;

class CashRegisterSessionPolicy
{
    private function staff(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('cashier');
    }

    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, CashRegisterSession $session): bool
    {
        return $this->staff($user) && $this->sameBranch($user, $session);
    }

    public function open(User $user): bool
    {
        return $this->staff($user);
    }

    public function close(User $user, CashRegisterSession $session): bool
    {
        return $this->staff($user) && $this->sameBranch($user, $session);
    }

    private function sameBranch(User $user, CashRegisterSession $session): bool
    {
        $session->loadMissing('cashRegister');

        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->branch_id !== null
            && (int) $session->cashRegister->branch_id === (int) $user->branch_id;
    }
}
