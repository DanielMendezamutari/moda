<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    private function canView(User $user): bool
    {
        return $user->can('purchases.view') || $user->hasRole('admin');
    }

    private function canManage(User $user): bool
    {
        return $user->can('purchases.manage') || $user->hasRole('admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $this->canManage($user);
    }
}
