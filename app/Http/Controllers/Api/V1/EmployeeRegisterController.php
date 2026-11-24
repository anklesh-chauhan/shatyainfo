<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeRegisterRequest;
use App\Models\Employee;
use App\Services\HR\EmployeeRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class EmployeeRegisterController extends Controller
{
    public function __construct(private EmployeeRegister $employees) {}

    public function index(EmployeeRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->employees->listQuery($actor, $request->validated())->orderBy($request->validated('sort', 'first_name'), $request->validated('direction', 'asc'))->orderBy('id', $request->validated('direction', 'asc'))->paginate(30)
            ->through(fn (Employee $employee): array => $this->employees->summary($actor, $employee));

        return $this->json($page->toArray() + ['can_create' => $actor->can('Create:Employee'), 'bulk_actions' => array_values(array_filter(['submit', 'delete', 'restore'], fn (string $action): bool => $actor->can(match ($action) {
            'delete' => 'DeleteAny:Employee', 'restore' => 'RestoreAny:Employee', default => 'Update:Employee'
        }) && ($action !== 'submit' || Employee::moduleHasApprovalFlow())))]);
    }

    public function form(EmployeeRegisterRequest $request): JsonResponse
    {
        $this->employees->authorize($request->user());

        return $this->json(['fields' => $this->employees->formFields($request->user(), 'employee')]);
    }

    public function options(EmployeeRegisterRequest $request, string $kind): JsonResponse
    {
        return $this->json($this->employees->options($request->user(), $kind, $request->validated()));
    }

    public function createOption(EmployeeRegisterRequest $request, string $createKind): JsonResponse
    {
        $grade = $this->employees->createGrade($request->user(), $request->validated('grade_name'));

        return $this->json(['data' => ['id' => $grade->id, 'label' => $grade->grade_name]], 201);
    }

    public function show(EmployeeRegisterRequest $request, int $employee): JsonResponse
    {
        return $this->json(['data' => $this->employees->detail($request->user(), $this->employees->find($request->user(), $employee))]);
    }

    public function store(EmployeeRegisterRequest $request): JsonResponse
    {
        return $this->json(['data' => ['id' => $this->employees->save($request->user(), null, $request->validated())->id]], 201);
    }

    public function update(EmployeeRegisterRequest $request, int $employee): JsonResponse
    {
        return $this->json(['data' => $this->employees->detail($request->user(), $this->employees->save($request->user(), $employee, $request->validated()))]);
    }

    public function action(EmployeeRegisterRequest $request, int $employee, string $action): JsonResponse
    {
        $this->employees->act($request->user(), [$employee], $action, $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function bulk(EmployeeRegisterRequest $request, string $action): JsonResponse
    {
        $this->employees->act($request->user(), $request->validated('ids'), $action, bulk: true);

        return $this->json(['data' => ['changed' => true]]);
    }

    public function section(EmployeeRegisterRequest $request, int $employee, string $section): JsonResponse
    {
        return $this->json($this->employees->sectionData($request->user(), $employee, $section));
    }

    public function changeSection(EmployeeRegisterRequest $request, int $employee, string $section, ?int $row = null): JsonResponse
    {
        $this->employees->changeSection($request->user(), $employee, $section, $row, $request->isMethod('DELETE') ? null : $request->validated(), $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function bulkRows(EmployeeRegisterRequest $request, int $employee, string $section, string $rowAction): JsonResponse
    {
        $this->employees->deleteRows($request->user(), $employee, $section, $request->validated('ids'), $request->validated('revision'));

        return $this->json(['data' => ['changed' => true]]);
    }

    public function requestChange(EmployeeRegisterRequest $request, int $employee, string $changeType): JsonResponse
    {
        $change = $this->employees->requestChange($request->user(), $employee, $changeType, $request->validated());

        return $this->json(['data' => ['id' => $change->id, 'approval_status' => $change->approval_status]], 201);
    }

    public function image(EmployeeRegisterRequest $request, int $employee): Response
    {
        $record = $this->employees->find($request->user(), $employee);
        abort_unless($record->profile_picture && Storage::exists($record->profile_picture), 404);
        $bytes = Storage::get($record->profile_picture);
        $image = @getimagesizefromstring($bytes);
        abort_unless($image && in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return response($bytes, 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
