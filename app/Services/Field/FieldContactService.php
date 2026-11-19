<?php

namespace App\Services\Field;

use App\Models\ContactDetail;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FieldContactService
{
    /**
     * @return LengthAwarePaginator<int, ContactDetail>
     */
    public function list(User $user, ?string $search = null, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', ContactDetail::class), 403);

        $query = ContactDetail::query()
            ->applyVisibility('ContactDetail', $user)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereLike('first_name', $term)
                    ->orWhereLike('last_name', $term)
                    ->orWhereLike('mobile_number', $term);
            });
        }

        return $query->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }
}
