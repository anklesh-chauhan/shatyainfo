<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Models\ContactDetail;
use App\Models\FollowUp;
use App\Models\LeadActivity;
use App\Services\DateFormatService;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class LeadFollowUpRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    protected static ?string $title = 'Follow-ups';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('user_id')
                    ->default(Auth::id()) // Automatically sets the current logged-in user
                    ->required(),

                Hidden::make('lead_id')
                    ->default(fn (callable $get) => $get('record.id')),

                DateTimePicker::make('follow_up_date')
                    ->required()
                    ->label('Follow-up Date'),

                Select::make('to_whom')
                    ->options(function () {
                        $lead = $this->getOwnerRecord();
                        if ($lead) {
                            return ContactDetail::where('company_id', $lead->company_id)
                                ->get()
                                ->mapWithKeys(fn ($contact) => [
                                    $contact->id => "{$contact->first_name} {$contact->last_name}",
                                ]);
                        }

                        return [];
                    })
                    ->searchable()
                    ->preload()
                    ->label('To Whom'),

                Textarea::make('interaction')
                    ->label('Interaction')
                    ->rows(3)
                    ->nullable(),

                Textarea::make('outcome')
                    ->label('Outcome')
                    ->rows(2)
                    ->nullable(),

                Select::make('follow_up_media_id')
                    ->relationship('media', 'name')
                    ->nullable(),

                Select::make('follow_up_result_id')
                    ->label('Result')
                    ->relationship('result', 'name')
                    ->nullable(),

                DateTimePicker::make('next_follow_up_date')
                    ->label('Next Follow-up Date')
                    ->nullable(),

                Select::make('follow_up_priority_id')
                    ->relationship('priority', 'name')
                    ->nullable(),

                Select::make('follow_up_status_id')
                    ->relationship('status', 'name')
                    ->required(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('Lead Follow-ups')
            ->columns([
                TextColumn::make('follow_up_date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('media.name')
                    ->searchable(),

                TextColumn::make('contactDetail.full_name')
                    ->label('To Whom')
                    ->tooltip(fn ($record) => $record->contactDetail
                        ? "Email: {$record->contactDetail->email}\nPhone: {$record->contactDetail->mobile_number}"
                        : 'No contact details available.')
                    ->sortable()
                    ->searchable(query: function ($query, $search) {
                        $query->whereHas('contactDetail', function ($q) use ($search) {
                            $q->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                        });
                    }),

                TextColumn::make('result.name')
                    ->searchable(),
                TextColumn::make('next_follow_up_date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('priority.name')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (RelationManager $livewire, array $data) {
                        $lead = $livewire->getOwnerRecord(); // Get the lead (parent record)
                        $followUp = FollowUp::latest()->first(); // Get the latest follow-up

                        if ($lead && $followUp) {
                            LeadActivity::create([
                                'lead_id' => $lead->id,
                                'user_id' => Auth::id(),
                                'activity_type' => 'Follow-up Created',
                                'description' => 'A new follow-up has been added on '.
                                    Carbon::parse($followUp->followup_date)->format(app(DateFormatService::class)->dateFormat().' h:i A').' using '.
                                    ($followUp->media->name ?? 'Unknown').' as media type.',
                            ]);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (RelationManager $livewire, array $data) {
                        $lead = $livewire->getOwnerRecord(); // Get the lead (parent record)
                        $followUp = FollowUp::latest()->first(); // Get the latest follow-up

                        if ($lead && $followUp) {
                            LeadActivity::create([
                                'lead_id' => $lead->id,
                                'user_id' => Auth::id(),
                                'activity_type' => 'Follow-up Updated',
                                'description' => 'A new follow-up has been added on '.
                                    Carbon::parse($followUp->followup_date)->format(app(DateFormatService::class)->dateFormat().' h:i A').' using '.
                                    ($followUp->media->name ?? 'Unknown').' as media type.',
                            ]);
                        }
                    }),
                DeleteAction::make()
                    ->after(function (RelationManager $livewire, array $data) {
                        $lead = $livewire->getOwnerRecord(); // Get the lead (parent record)
                        $followUp = FollowUp::latest()->first(); // Get the latest follow-up

                        if ($lead && $followUp) {
                            LeadActivity::create([
                                'lead_id' => $lead->id,
                                'user_id' => Auth::id(),
                                'activity_type' => 'Follow-up Deleted',
                                'description' => 'A new follow-up has been added on '.
                                    Carbon::parse($followUp->followup_date)->format(app(DateFormatService::class)->dateFormat().' h:i A').' using '.
                                    ($followUp->media->name ?? 'Unknown').' as media type.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
