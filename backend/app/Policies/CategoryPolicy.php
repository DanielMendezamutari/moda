<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Catálogo: cajero puede listar activas vía permisos de producto; ABM solo products.manage.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.manage')
            || $user->can('pos.sale.create');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->can('products.manage');
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->can('products.manage');
    }

    public function restore(User $user, Category $category): bool
    {
        return $user->can('products.manage');
    }

    public function forceDelete(User $user, Category $category): bool
    {
        return $user->can('products.manage');
    }
}
