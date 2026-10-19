<?php

namespace App\Policies;

use App\Models\HrChangeRequest;
use App\Models\User;

class HrChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:HrChangeRequest');
    }

    public function view(User $user, HrChangeRequest $record): bool
    {
        return $user->can('View:HrChangeRequest') && ($record->request_type !== 'salary' || $user->can('Update:EmpSalary'));
    }

    public function create(User $user): bool
    {
        return $user->can('Create:HrChangeRequest');
    }

    public function update(User $user, HrChangeRequest $record): bool
    {
        return false;
    }

    public function delete(User $user, HrChangeRequest $record): bool
    {
        return false;
    }
}
