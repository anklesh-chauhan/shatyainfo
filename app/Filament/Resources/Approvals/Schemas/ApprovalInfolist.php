<?php

namespace App\Filament\Resources\Approvals\Schemas;

use App\Models\Approval;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ApprovalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request Snapshot')
                    ->schema([
                        TextEntry::make('module')->badge(),
                        TextEntry::make('record_type')->formatStateUsing(fn (?string $state): ?string => $state ? str(class_basename($state))->headline()->toString() : null),
                        TextEntry::make('record_id'),
                        TextEntry::make('requested_amount')->money('INR'),
                        TextEntry::make('territory.name')->placeholder('-'),
                        TextEntry::make('requester.name')->label('Requested By')->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Flow Snapshot')
                    ->schema([
                        TextEntry::make('flow.module')->label('Flow'),
                        TextEntry::make('flow_version'),
                        TextEntry::make('approval_status')->badge(),
                        TextEntry::make('completed_at')->dateTime()->placeholder('-'),
                        TextEntry::make('finalized_at')->dateTime()->placeholder('-'),
                    ])
                    ->columns(3),

                Section::make('Selected Routing')
                    ->schema([
                        RepeatableEntry::make('selected_steps')
                            ->label('Steps')
                            ->placeholder('No routing steps recorded')
                            ->schema([
                                TextEntry::make('step_order')->label('Step'),
                                TextEntry::make('routing_type')->label('Routing')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())->placeholder('-'),
                                TextEntry::make('territory_scope')->label('Territory scope')->formatStateUsing(fn (string $state): string => $state === 'all' ? 'All territories' : str($state)->headline()->toString())->placeholder('-'),
                                TextEntry::make('can_skip')->label('Optional step')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->placeholder('-'),
                                TextEntry::make('sla_hours')->label('Response time')->suffix(' hours')->placeholder('-'),
                                TextEntry::make('reason')->label('Routing reason')->placeholder('-')->columnSpanFull(),
                            ])
                            ->columns(2),
                        RepeatableEntry::make('selected_approvers')
                            ->label('Approvers')
                            ->placeholder('No approvers recorded')
                            ->schema([
                                TextEntry::make('step_order')->label('Step'),
                                TextEntry::make('name')->label('Approver')->placeholder('-'),
                                TextEntry::make('original_name')->label('Originally selected approver')->placeholder('-'),
                                TextEntry::make('reason')->label('Selection reason')->placeholder('-')->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Submitted Record')
                    ->schema([
                        KeyValueEntry::make('submitted_record_summary')
                            ->label('Submitted details')
                            ->keyLabel('Field')
                            ->valueLabel('Submitted value')
                            ->state(fn (Approval $record): array => self::submittedDetails($record->submitted_record_summary ?? []))
                            ->placeholder('No submitted details recorded'),
                    ])
                    ->columnSpanFull(),

                Section::make('Activity')
                    ->schema([
                        RepeatableEntry::make('activities')
                            ->schema([
                                TextEntry::make('created_at')->dateTime(),
                                TextEntry::make('action')->badge(),
                                TextEntry::make('actor.name')->placeholder('-'),
                                TextEntry::make('from_status')->placeholder('-'),
                                TextEntry::make('to_status')->placeholder('-'),
                                TextEntry::make('comments')->placeholder('-'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, string>
     */
    protected static function submittedDetails(array $summary): array
    {
        $fields = array_merge(Arr::except($summary, ['class', 'attributes']), $summary['attributes'] ?? []);
        $details = [];

        foreach (Arr::dot($fields) as $key => $value) {
            $field = (string) str($key)->afterLast('.');

            if ($field === 'id' || str_ends_with($field, '_id') || in_array($field, ['created_by', 'updated_by', 'deleted_by'], true)) {
                continue;
            }

            $label = str($key)->replace('.', ' / ')->headline()->toString();
            $details[$label] = match (true) {
                $value === null, $value === '' => '-',
                is_bool($value) => $value ? 'Yes' : 'No',
                $field === 'month' && is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 => Carbon::createFromFormat('!Y-m', $value)->format('F Y'),
                is_string($value) && str_ends_with($field, 'status') => str($value)->headline()->toString(),
                is_array($value) => '-',
                default => (string) $value,
            };
        }

        return $details;
    }
}
