<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Catálogo para cobrar en POS: cajero puede ver; ABM solo con products.manage.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.manage')
            || $user->can('pos.sale.create')
            || $user->hasRole('admin');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }
}
