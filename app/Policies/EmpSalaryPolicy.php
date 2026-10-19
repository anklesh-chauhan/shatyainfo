<?php

namespace App\Policies;

use App\Models\EmpSalary;
use App\Models\User;

class EmpSalaryPolicy
{
    public function update(User $user, EmpSalary $salary): bool
    {
        return $user->can('Update:EmpSalary');
    }
}
