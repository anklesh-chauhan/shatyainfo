<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContactResource;
use App\Services\Field\FieldContactService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function __construct(private FieldContactService $contacts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        return ContactResource::collection(
            $this->contacts->list(
                $request->user(),
                is_string($search) && $search !== '' ? $search : null,
                $perPage,
            ),
        );
    }
}
