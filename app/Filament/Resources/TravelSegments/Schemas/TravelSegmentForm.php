<?php

namespace App\Filament\Resources\TravelSegments\Schemas;

use App\Models\CityPinCode;
use App\Models\SalesDcr;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TravelSegmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('sales_dcr_id')
                    ->label('Daily call report')
                    ->relationship('salesDcr', 'dcr_date', modifyQueryUsing: fn (Builder $query) => $query->with('user'))
                    ->getOptionLabelFromRecordUsing(fn (SalesDcr $record): string => '#'.$record->id.' — '.$record->dcr_date?->format('Y-m-d').' — '.$record->user?->name)
                    ->searchable(['id', 'dcr_date'])
                    ->optionsLimit(50)
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('visit_id', null);
                        $set('sales_tour_plan_detail_id', null);
                    })
                    ->required(),
                Select::make('visit_id')
                    ->relationship('visit', 'document_number', modifyQueryUsing: fn (Builder $query, Get $get) => $query->where('sales_dcr_id', $get('sales_dcr_id') ?? 0))
                    ->searchable()->optionsLimit(50),
                Select::make('sales_tour_plan_detail_id')
                    ->relationship('salesTourPlanDetail', 'date', modifyQueryUsing: function (Builder $query, Get $get): Builder {
                        $dcr = SalesDcr::find($get('sales_dcr_id'));

                        return $query->where('sales_tour_plan_id', $dcr?->sales_tour_plan_id ?? 0)->whereDate('date', $dcr?->dcr_date);
                    })
                    ->searchable()->optionsLimit(50),
                Select::make('patch_id')
                    ->relationship('patch', 'name', modifyQueryUsing: fn (Builder $query, Get $get) => $query->where('territory_id', SalesDcr::find($get('sales_dcr_id'))?->territory_id ?? 0))->searchable(['name', 'code'])->optionsLimit(50),
                Select::make('from_account_id')
                    ->relationship('fromAccount', 'name')->searchable(['name', 'account_code'])->optionsLimit(50),
                Select::make('to_account_id')
                    ->relationship('toAccount', 'name')->searchable(['name', 'account_code'])->optionsLimit(50),
                Select::make('from_area_town_id')
                    ->relationship('fromAreaTown', 'area_town', modifyQueryUsing: fn (Builder $query) => $query->with('city'))
                    ->getOptionLabelFromRecordUsing(fn (CityPinCode $record): string => $record->full_location)
                    ->searchable(['area_town', 'pin_code'])->optionsLimit(50),
                Select::make('to_area_town_id')
                    ->relationship('toAreaTown', 'area_town', modifyQueryUsing: fn (Builder $query) => $query->with('city'))
                    ->getOptionLabelFromRecordUsing(fn (CityPinCode $record): string => $record->full_location)
                    ->searchable(['area_town', 'pin_code'])->optionsLimit(50),
                Select::make('transport_mode_id')
                    ->relationship('transportMode', 'name')->searchable()->optionsLimit(50),
                TextInput::make('distance_km')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0.0),
                TextInput::make('distance_source')
                    ->required()
                    ->default('manual'),
                TextInput::make('gps_distance_km')
                    ->numeric(),
                Toggle::make('is_auto_generated')
                    ->required(),
            ]);
    }
}
