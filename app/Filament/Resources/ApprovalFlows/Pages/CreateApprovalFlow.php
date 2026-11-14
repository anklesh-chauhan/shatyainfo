<?php

namespace App\Filament\Resources\ApprovalFlows\Pages;

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Models\ApprovalFlow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApprovalFlow extends CreateRecord
{
    protected static string $resource = ApprovalFlowResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return (new ApprovalFlow)->saveConfiguration($data);
    }
}
