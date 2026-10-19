<?php

namespace App\Policies;

use App\Models\EmploymentDetail;
use App\Models\User;

class EmploymentDetailPolicy
{
    public function update(User $user, EmploymentDetail $detail): bool
    {
        return $user->can('update', $detail->employee);
    }
}
