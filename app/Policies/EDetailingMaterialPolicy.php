<?php

namespace App\Policies;

use App\Models\EDetailingMaterial;
use App\Models\User;

class EDetailingMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'administration_admin']) || $user->can('ManageEDetailing');
    }

    public function view(User $user, EDetailingMaterial $material): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, EDetailingMaterial $material): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, EDetailingMaterial $material): bool
    {
        return $this->viewAny($user);
    }
}
