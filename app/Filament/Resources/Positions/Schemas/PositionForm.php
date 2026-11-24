<?php

namespace App\Filament\Resources\Positions\Schemas;

use App\Models\Position;
use App\Services\HR\PositionRegister;
use App\Services\OrganizationalUnitService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

class PositionForm
{
    public static function fields(): array
    {
        $fields = [Hidden::make('revision')];
        foreach (app(PositionRegister::class)->fields() as $field) {
            $key = $field['key'];
            if (isset($field['kind'])) {
                $kind = $field['kind'];
                $component = Select::make($key)->searchable()->options(function (Get $get, ?Position $record) use ($kind, $key): array {
                    $service = app(PositionRegister::class);
                    $query = $service->catalog(auth()->user(), $kind, $record?->id, $kind === 'org-units' ? ($get('division_ou_id') ? (int) $get('division_ou_id') : 0) : null);
                    if ($key === 'hq_territory_id') {
                        $query->whereKey($get('territory_ids') ?? []);
                    }
                    $column = PositionRegister::OPTIONS[$kind][1];
                    $options = $query->orderBy($column)->get()->mapWithKeys(fn ($row): array => [$row->id => $kind === 'employees' ? $row->employee_id.' - '.$row->full_name : $row->{$column}])->all();
                    foreach ((array) $get($key) as $id) {
                        if ($id && ! isset($options[$id])) {
                            $options[$id] = 'Linked selection #'.$id;
                        }
                    }

                    return $options;
                });
                if ($field['type'] === 'multi') {
                    $component->multiple()->default([]);
                }
                if ($key === 'employee_ids') {
                    $component->disabled(fn (): bool => ! auth()->user()->can(['ViewAny:Employee', 'View:Employee', 'Update:Employee']))->dehydrated();
                }
                if ($key === 'org_unit_ids') {
                    $component->rules([fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        try {
                            OrganizationalUnitService::validateSelection($get('division_ou_id') ? (int) $get('division_ou_id') : null, (array) $value);
                        } catch (ValidationException $exception) {
                            $fail('Choose organizational units within the selected Division.');
                        }
                    }]);
                }
                if ($key === 'territory_ids') {
                    $component->live()->afterStateUpdated(function (Set $set, Get $get): void {
                        if (! in_array($get('hq_territory_id'), $get('territory_ids') ?? [])) {
                            $set('hq_territory_id', null);
                        }
                    });
                }
                if ($key === 'division_ou_id') {
                    $component->live()->afterStateUpdated(fn (Set $set) => $set('org_unit_ids', []));
                }
            } elseif ($key === 'status') {
                $component = Select::make($key)->options($field['options'])->default('active');
            } else {
                $component = ($key === 'description' ? Textarea::make($key)->columnSpanFull() : TextInput::make($key))->maxLength($field['max']);
            }
            $fields[] = $component->label($field['label'])->required($field['required']);
        }

        return $fields;
    }
}
