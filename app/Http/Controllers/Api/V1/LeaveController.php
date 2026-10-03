<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeaveApplicationRequest;
use App\Http\Resources\Api\V1\LeaveApplicationResource;
use App\Http\Resources\Api\V1\LeaveTypeResource;
use App\Services\Field\FieldLeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveController extends Controller
{
    public function __construct(
        private FieldLeaveService $leaveService,
    ) {}

    public function types(Request $request): AnonymousResourceCollection
    {
        return LeaveTypeResource::collection(
            $this->leaveService->types($request->user()),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return LeaveApplicationResource::collection(
            $this->leaveService->applications($request->user()),
        );
    }

    public function store(StoreLeaveApplicationRequest $request): LeaveApplicationResource
    {
        return new LeaveApplicationResource(
            $this->leaveService->apply($request->user(), $request->validated()),
        );
    }

    public function cancel(Request $request, int $application): LeaveApplicationResource
    {
        return new LeaveApplicationResource(
            $this->leaveService->cancel($request->user(), $application),
        );
    }
}
