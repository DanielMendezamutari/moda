<?php

namespace App\Policies;

use App\Models\Transport;
use App\Models\User;

class TransportPolicy
{
    private function canView(User $user): bool
    {
        return $user->can('transports.view') || $user->hasRole('admin');
    }

    private function canManage(User $user): bool
    {
        return $user->can('transports.manage') || $user->hasRole('admin');
    }

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Transport $transport): bool
    {
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Transport $transport): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Transport $transport): bool
    {
        return $this->canManage($user);
    }
}
