<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;

class CashRegisterPolicy
{
    private function isCashStaff(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('cashier');
    }

    public function viewAny(User $user): bool
    {
        return $this->isCashStaff($user);
    }

    public function view(User $user, CashRegister $register): bool
    {
        return $this->canAccessBranch($user, (int) $register->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, CashRegister $register): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, CashRegister $register): bool
    {
        return $user->hasRole('admin');
    }

    public function canAccessBranch(User $user, int $branchId): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->branch_id !== null && (int) $user->branch_id === $branchId;
    }
}
