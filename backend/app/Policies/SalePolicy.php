<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    private function canViewPos(User $user): bool
    {
        return $user->can('pos.sale.view')
            || $user->hasRole('admin')
            || $user->hasRole('cashier');
    }

    private function canCreatePos(User $user): bool
    {
        return $user->can('pos.sale.create')
            || $user->hasRole('admin')
            || $user->hasRole('cashier');
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewPos($user);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->canViewPos($user);
    }

    public function create(User $user): bool
    {
        return $this->canCreatePos($user);
    }

    public function update(User $user, Sale $sale): bool
    {
        return $this->canCreatePos($user);
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $user->can('products.manage');
    }

    public function restore(User $user, Sale $sale): bool
    {
        return $user->can('products.manage');
    }

    public function forceDelete(User $user, Sale $sale): bool
    {
        return $user->can('products.manage');
    }
}
