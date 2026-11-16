<?php

namespace App\Policies;

use App\Filament\Exports\Reports\AttendanceRegisterExporter;
use App\Filament\Exports\Reports\DcrRegisterExporter;
use App\Filament\Exports\Reports\DealPipelineExporter;
use App\Filament\Exports\Reports\DoctorSampleLedgerExporter;
use App\Filament\Exports\Reports\ExpenseSummaryExporter;
use App\Filament\Exports\Reports\LeadPipelineExporter;
use App\Filament\Exports\Reports\LeaveLedgerExporter;
use App\Filament\Exports\Reports\OverdueFollowUpExporter;
use App\Filament\Exports\Reports\PunchVsTourExporter;
use App\Filament\Exports\Reports\SalesDocumentRegisterExporter;
use App\Filament\Exports\Reports\SgipComplianceExporter;
use App\Filament\Exports\Reports\StockMovementRegisterExporter;
use App\Filament\Exports\Reports\VisitCoverageExporter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OptionalModuleService;
use App\Services\OrganizationalUnitService;
use App\Services\PositionService;
use App\Services\Reports\ReportVisibility;
use Filament\Actions\Exports\Models\Export;

class ReportExportPolicy
{
    private const REPORTS = [
        AttendanceRegisterExporter::class => 'AttendanceRegister',
        DcrRegisterExporter::class => 'DcrRegister',
        DealPipelineExporter::class => 'DealPipeline',
        DoctorSampleLedgerExporter::class => 'DoctorSampleLedger',
        ExpenseSummaryExporter::class => 'ExpenseSummary',
        LeadPipelineExporter::class => 'LeadPipeline',
        LeaveLedgerExporter::class => 'LeaveLedger',
        OverdueFollowUpExporter::class => 'OverdueFollowUps',
        PunchVsTourExporter::class => 'PunchVsTour',
        SalesDocumentRegisterExporter::class => 'SalesDocumentRegister',
        SgipComplianceExporter::class => 'SgipCompliance',
        StockMovementRegisterExporter::class => 'StockMovementRegister',
        VisitCoverageExporter::class => 'VisitCoverage',
    ];

    public function isReport(Export $export): bool
    {
        return str_starts_with($export->exporter, 'App\\Filament\\Exports\\Reports\\');
    }

    public function fingerprint(User $user): string
    {
        $scope = [
            'company' => Tenant::current()?->getKey(),
            'user' => $user->getKey(),
            'roles' => $user->getRoleNames()->sort()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'modules' => app(OptionalModuleService::class)->capabilities()['enabled'],
        ];

        if (! ReportVisibility::hasFullAccess($user)) {
            $scope['territories'] = collect(PositionService::getTerritoryIdsForUser($user))->sort()->values()->all();
            $scope['org_units'] = collect(OrganizationalUnitService::getUserOuIds($user))->sort()->values()->all();
            $scope['visible_users'] = PositionService::getVisibleUserIdsFor($user)->sort()->values()->all();
        }

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function view(User $user, Export $export): bool
    {
        if (! $export->user()->is($user)) {
            return false;
        }

        $report = self::REPORTS[$export->exporter] ?? null;

        return $report !== null
            && $user->canAccessBusinessPage('View:'.$report)
            && $user->canAccessBusinessPage('Export:Reports')
            && is_string($export->scope_fingerprint)
            && hash_equals($export->scope_fingerprint, $this->fingerprint($user));
    }
}
