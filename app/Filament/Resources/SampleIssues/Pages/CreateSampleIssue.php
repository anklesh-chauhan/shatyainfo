<?php

namespace App\Filament\Resources\SampleIssues\Pages;

use App\Filament\Resources\SampleIssues\SampleIssueResource;
use App\Filament\Resources\SampleIssues\Schemas\SampleIssueForm;
use App\Services\Inventory\SampleIssueRegister;
use App\Services\Inventory\SampleRequestRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSampleIssue extends CreateRecord
{
    protected static string $resource = SampleIssueResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    public function mount(): void
    {
        parent::mount();

        $sampleRequestId = request()->integer('sample_request_id');

        if (! $sampleRequestId) {
            return;
        }

        $register = app(SampleRequestRegister::class);
        $sampleRequest = $register->find(auth()->user(), $sampleRequestId);
        abort_unless($register->canHandoff(auth()->user(), $sampleRequest), 403);

        $this->data = [
            ...$this->data,
            'sample_request_id' => $sampleRequest->id,
            'to_location_id' => $sampleRequest->location_master_id,
            'issue_date' => now()->toDateString(),
            'lines' => SampleIssueForm::requestLines($sampleRequest),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SampleIssueRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
