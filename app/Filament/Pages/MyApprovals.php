<?php

namespace App\Filament\Pages;

use App\Models\ApprovalStep;
use App\Services\Approval\ApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class MyApprovals extends Page implements HasTable
{
    protected static ?int $navigationSort = 40;

    protected static string|\UnitEnum|null $navigationGroup = 'My Workspace';

    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'My Approvals';

    protected string $view = 'filament.pages.my-approvals';

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessBusinessPage('View:MyApprovals') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->maxSelectableRecords(100)
            ->query(fn () => ApprovalStep::query()
                ->currentFor((int) Auth::id())
                ->with([
                    'approval.approvable',
                    'approval.requestedBy',
                ])
            )
            ->columns([
                TextColumn::make('approval.approvable_type')
                    ->label('Module')
                    ->formatStateUsing(fn ($state) => class_basename($state)),

                TextColumn::make('approval.approvable.id')
                    ->label('Reference'),

                TextColumn::make('approval.requestedBy.name')
                    ->label('Requested By'),

                TextColumn::make('approval.created_at')
                    ->label('Requested On')
                    ->dateTime(),
            ])
            ->actions([
                Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (ApprovalStep $record) => app(ApprovalService::class)
                        ->approve($record->approval, Auth::id(), expectedStepId: $record->id)
                    ),

                Action::make('reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('comments')
                            ->required(),
                    ])
                    ->action(fn (ApprovalStep $record, array $data) => app(ApprovalService::class)
                        ->reject(
                            $record->approval,
                            Auth::id(),
                            $data['comments'],
                            expectedStepId: $record->id
                        )
                    ),
            ])
            ->emptyStateHeading('No pending approvals')
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve selected')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Approve all selected requests? If any request has changed, refresh and select again.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $accepted = app(ApprovalService::class)->decideMany($records->modelKeys(), (int) Auth::id(), 'approve');
                        Notification::make()
                            ->title($accepted ? 'Selected requests approved' : 'A selected request has changed. Refresh and select again.')
                            ->status($accepted ? 'success' : 'warning')->send();
                    }),
                BulkAction::make('rejectSelected')
                    ->label('Reject selected')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('comments')->label('Reason')->required()->maxLength(2000)])
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        $accepted = app(ApprovalService::class)->decideMany($records->modelKeys(), (int) Auth::id(), 'reject', $data['comments']);
                        Notification::make()
                            ->title($accepted ? 'Selected requests rejected' : 'A selected request has changed. Refresh and select again.')
                            ->status($accepted ? 'success' : 'warning')->send();
                    }),
            ])
            ->emptyStateDescription('You currently have no approvals waiting for you.');
    }
}
