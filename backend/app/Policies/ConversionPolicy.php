<?php

namespace App\Policies;

use App\Models\Conversion;
use App\Models\User;

class ConversionPolicy
{
    private function canView(User $user): bool
    {
        return $user->can('conversions.view') || $user->hasRole('admin');
    }

    private function canManage(User $user): bool
    {
        return $user->can('conversions.manage') || $user->hasRole('admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Conversion $conversion): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
