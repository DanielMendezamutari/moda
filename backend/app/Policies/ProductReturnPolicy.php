<?php

namespace App\Policies;

use App\Models\ProductReturn;
use App\Models\User;

class ProductReturnPolicy
{
    private function canView(User $user): bool
    {
        return $user->can('returns.view')
            || $user->hasRole('admin')
            || $user->hasRole('cashier');
    }

    private function canManage(User $user): bool
    {
        return $user->can('returns.manage')
            || $user->hasRole('admin')
            || $user->hasRole('cashier');
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ProductReturn $productReturn): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ProductReturn $productReturn): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, ProductReturn $productReturn): bool
    {
        return $this->canManage($user);
    }
}
