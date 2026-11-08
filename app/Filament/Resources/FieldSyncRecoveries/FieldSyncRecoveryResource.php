<?php

namespace App\Filament\Resources\FieldSyncRecoveries;

use App\Filament\Clusters\GlobalConfiguration\OrganizationAndCompanyCluster;
use App\Filament\Resources\FieldSyncRecoveries\Pages\ManageFieldSyncRecoveries;
use App\Models\FieldSyncRecovery;
use App\Models\ItemMaster;
use App\Models\User;
use App\Services\Field\FieldConflictResolutionService;
use App\Services\Field\FieldPlanConflictService;
use App\Services\Field\FieldReplacementService;
use App\Services\Field\FieldSgipStockService;
use App\Services\Field\FieldSyncRecoveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class FieldSyncRecoveryResource extends Resource
{
    protected static ?int $navigationSort = 40;

    protected static ?string $cluster = OrganizationAndCompanyCluster::class;

    protected static ?string $model = FieldSyncRecovery::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'reviewer:id,name'])->where(function (Builder $query): void {
            $query->where('user_id', auth()->id());
            if (auth()->user()->can('Review:FieldSyncRecovery')) {
                $query->orWhere('reviewer_id', auth()->id());
            }
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Representative'), TextColumn::make('report_date')->date()->sortable(),
                TextColumn::make('path')->label('Operation'), TextColumn::make('status')->badge(), TextColumn::make('reviewer.name')->label('Assigned reviewer'),
                TextColumn::make('last_error')->wrap()->limit(160), TextColumn::make('expires_at')->dateTime(),
                TextColumn::make('resolution_mode')->label('Requested resolution')->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('evidence')->label('Inspect original request')->schema([
                    Textarea::make('snapshot')->label('Original fields and attachment hashes')->default(fn (FieldSyncRecovery $record) => json_encode($record->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(18)->disabled()->dehydrated(false),
                    Textarea::make('history')->label('Review history')->default(fn (FieldSyncRecovery $record) => json_encode(['reason' => $record->reason, 'note' => $record->review_note, 'history' => $record->review_history], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->disabled()->dehydrated(false),
                    Textarea::make('server_snapshot')->label('Server record captured for review')->default(fn (FieldSyncRecovery $record) => json_encode($record->resolution_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(12)->disabled()->dehydrated(false),
                    Textarea::make('replacement_evidence')->label('Proposed corrected transaction')->default(fn (FieldSyncRecovery $record) => json_encode($record->replacement_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(10)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('requestReview')->label('Request late-window review')->visible(fn (FieldSyncRecovery $record) => ($record->resolution_mode === 'retry_original' || $record->recovered_at) && (int) $record->user_id === (int) auth()->id() && ($record->status === 'failed' || (in_array($record->status, ['approved', 'recovered'], true) && $record->expires_at?->isPast())) && app(FieldSyncRecoveryService::class)->needsWindowException($record))
                    ->schema([
                        Select::make('reviewer_id')->label('Authorized manager')->options(fn () => User::query()->where('id', '!=', auth()->id())->get()->filter(fn ($user) => $user->can('Review:FieldSyncRecovery'))->pluck('name', 'id'))->required(),
                        Textarea::make('reason')->label('Reason for delayed synchronization')->required()->minLength(5)->maxLength(2000),
                    ])->action(fn (FieldSyncRecovery $record, array $data) => app(FieldSyncRecoveryService::class)->requestReview(auth()->user(), $record, $data)),
                Action::make('keepServer')->label('Request keep-server resolution')->visible(fn (FieldSyncRecovery $record) => (int) $record->user_id === (int) auth()->id() && app(FieldConflictResolutionService::class)->eligible($record))
                    ->modalDescription('The failed sample/order changes will not be applied. Reconcile any physical samples or customer commitments first and include supporting document references in the reason. The original evidence is retained.')
                    ->schema([
                        Select::make('reviewer_id')->label('Authorized manager')->options(fn () => User::query()->where('id', '!=', auth()->id())->get()->filter(fn ($user) => $user->can('Review:FieldSyncRecovery'))->pluck('name', 'id'))->required(),
                        Textarea::make('reason')->label('Reconciliation evidence and document references')->required()->minLength(5)->maxLength(2000),
                        Checkbox::make('confirmed_no_pending_obligation')->label('Physical samples and customer order obligations are reconciled; these failed changes need not be posted.')->accepted(),
                    ])->action(fn (FieldSyncRecovery $record, array $data) => app(FieldSyncRecoveryService::class)->requestReview(auth()->user(), $record, [...$data, 'resolution_mode' => 'keep_server'])),
                Action::make('replacement')->label('Propose corrected replacement')->visible(fn (FieldSyncRecovery $record) => (int) $record->user_id === (int) auth()->id() && app(FieldConflictResolutionService::class)->eligible($record))
                    ->modalDescription('For samples, list the complete corrected distribution. For orders, list only lines to change; zero removes a line. Prices are calculated by the server. Original call, date and evidence stay linked; submitted distributions and locked orders cannot be overwritten.')
                    ->schema(static::replacementSchema())
                    ->action(function (FieldSyncRecovery $record, array $data): void {
                        $replacement = Arr::only($data, ['items', 'sample_issue_id', 'change_description', 'description', 'change_delivery_date', 'delivery_date']);
                        $replacement['sample_issue_id'] = empty($replacement['sample_issue_id']) ? null : (int) $replacement['sample_issue_id'];
                        app(FieldSyncRecoveryService::class)->requestReview(auth()->user(), $record, [...$data, 'resolution_mode' => 'replacement', 'replacement' => $replacement]);
                    }),
                Action::make('acceptPlanCapture')->label('Request unplanned acceptance')->visible(fn (FieldSyncRecovery $record) => (int) $record->user_id === (int) auth()->id() && app(FieldPlanConflictService::class)->eligible($record))
                    ->modalDescription('Ask a manager to verify the actual captured call and accept it as unplanned. The original intention/version and all evidence remain in the audit. The plan is not reactivated or rewritten; current policy and DCR locks still apply.')
                    ->schema([
                        Select::make('reviewer_id')->label('Authorized manager')->options(fn () => User::query()->where('id', '!=', auth()->id())->get()->filter(fn ($user) => $user->can('Review:FieldSyncRecovery'))->pluck('name', 'id'))->required(),
                        Textarea::make('reason')->label('Actual visit evidence and reason for unplanned acceptance')->required()->minLength(5)->maxLength(2000),
                        Checkbox::make('confirmed_actual_visit')->label('This visit occurred and should be reviewed for acceptance as unplanned.')->accepted(),
                    ])->action(fn (FieldSyncRecovery $record, array $data) => app(FieldSyncRecoveryService::class)->requestReview(auth()->user(), $record, [...$data, 'resolution_mode' => FieldPlanConflictService::MODE])),
                Action::make('review')->label('Review resolution')->visible(fn (FieldSyncRecovery $record) => $record->status === 'pending_review' && (int) $record->reviewer_id === (int) auth()->id() && auth()->user()->can('Review:FieldSyncRecovery'))
                    ->schema([
                        Hidden::make('review_token')->default(fn (FieldSyncRecovery $record) => $record->reviewToken()),
                        Textarea::make('review_snapshot')->label('Server record captured for review')->default(fn (FieldSyncRecovery $record) => json_encode($record->resolution_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->disabled()->dehydrated(false),
                        Textarea::make('review_replacement')->label('Exact corrected fields to post')->default(fn (FieldSyncRecovery $record) => json_encode($record->replacement_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(10)->visible(fn (FieldSyncRecovery $record) => $record->resolution_mode === 'replacement')->disabled()->dehydrated(false),
                        Select::make('decision')->options(fn (FieldSyncRecovery $record) => ['approved' => match ($record->resolution_mode) {
                            'accept_unplanned' => 'Accept actual captured call as unplanned for seven days', 'replacement' => 'Authorize corrected replacement for seven days', 'keep_server' => 'Keep server record; do not post failed changes', default => 'Authorize this exact retry for seven days'
                        }, 'rejected' => 'Reject request'])->required(),
                        Textarea::make('note')->label('Review reason')->required()->minLength(5)->maxLength(2000),
                        Checkbox::make('confirmed_actual_visit')->label('I verified the actual visit and approve its acceptance as unplanned while retaining the original intention evidence.')->visible(fn (FieldSyncRecovery $record) => $record->resolution_mode === FieldPlanConflictService::MODE && ! $record->recovered_at),
                        Checkbox::make('confirmed_no_pending_obligation')->label('I verified that physical samples and customer order obligations are reconciled. Required when approving keep-server resolution.')->visible(fn (FieldSyncRecovery $record) => $record->resolution_mode === 'keep_server' && ! $record->recovered_at),
                        Checkbox::make('confirmed_replacement')->label('I verified the proposed correction against the actual distribution or customer order.')->visible(fn (FieldSyncRecovery $record) => $record->resolution_mode === 'replacement' && ! $record->recovered_at),
                    ])->action(fn (FieldSyncRecovery $record, array $data) => app(FieldSyncRecoveryService::class)->review(auth()->user(), $record, $data)),
            ]);
    }

    public static function replacementSchema(): array
    {
        $defaults = fn (FieldSyncRecovery $record) => app(FieldReplacementService::class)->formDefaults($record);
        $order = fn (FieldSyncRecovery $record) => $record->snapshot['request']['action'] === 'order';

        return [
            Select::make('reviewer_id')->label('Authorized manager')->options(fn () => User::query()->where('id', '!=', auth()->id())->get()->filter(fn ($user) => $user->can('Review:FieldSyncRecovery'))->pluck('name', 'id'))->required(),
            Repeater::make('items')->label('Corrected product quantities')->schema([
                Select::make('item_master_id')->label('Product')->options(fn () => ItemMaster::query()->orderBy('item_name')->pluck('item_name', 'id'))->searchable()->required(),
                TextInput::make('quantity')->numeric()->minValue(0)->maxValue(999999999)->required(),
            ])->default(fn (FieldSyncRecovery $record) => $defaults($record)['items'])->maxItems(100),
            Select::make('sample_issue_id')->label('Sample stock allocation')->options(fn () => collect(app(FieldSgipStockService::class)->snapshot(auth()->user())['sources'])->mapWithKeys(fn ($source) => [$source['sample_issue_id'] ?? 0 => $source['label']])->all())
                ->default(fn (FieldSyncRecovery $record) => $defaults($record)['sample_issue_id'] ?? 0)->visible(fn (FieldSyncRecovery $record) => ! $order($record))->required(),
            Checkbox::make('change_description')->label('Replace order note')->default(fn (FieldSyncRecovery $record) => $defaults($record)['change_description'] ?? false)->visible($order),
            Textarea::make('description')->label('Corrected order note')->maxLength(2000)->default(fn (FieldSyncRecovery $record) => $defaults($record)['description'] ?? '')->visible($order),
            Checkbox::make('change_delivery_date')->label('Replace delivery date (empty clears it)')->default(fn (FieldSyncRecovery $record) => $defaults($record)['change_delivery_date'] ?? false)->visible($order),
            DatePicker::make('delivery_date')->default(fn (FieldSyncRecovery $record) => $defaults($record)['delivery_date'] ?? null)->visible($order),
            Textarea::make('reason')->label('Correction reason and evidence')->required()->minLength(5)->maxLength(2000),
            Checkbox::make('confirmed_replacement')->label('These corrected quantities and details match the actual distribution or customer order.')->accepted(),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldSyncRecoveries::route('/'),
        ];
    }
}
