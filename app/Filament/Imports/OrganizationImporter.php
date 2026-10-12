<?php

namespace App\Filament\Imports;

use App\Models\Organization;
use Closure;
use DateTimeZone;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

class OrganizationImporter extends Importer
{
    protected static ?string $model = Organization::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->example('Acme Pharma Pvt Ltd')
                ->helperText('Existing organizations with this name are updated.')
                ->guess(['organization', 'organization_name', 'organisation']),
            self::optionalText('display_name', 'Acme Pharma'),
            self::optionalText('website', 'https://acme-pharma.test', ['nullable', 'url', 'max:255']),
            self::optionalDate('founded_at', '2012-04-01'),
            ImportColumn::make('industryType')
                ->label('Industry type')
                ->exampleHeader('industry_type')
                ->relationship(resolveUsing: 'name')
                ->ignoreBlankState()
                ->example('Pharmaceuticals')
                ->helperText('Must match an existing industry type name.'),
            self::optionalText('email', 'hello@acme-pharma.test', ['nullable', 'email', 'max:255']),
            self::optionalText('phone', '+91-11-4000-1000'),
            self::optionalText('fax', '+91-11-4000-1001'),
            self::optionalText('contact_person', 'Anita Shah'),
            self::optionalText('contact_person_email', 'anita@acme-pharma.test', ['nullable', 'email', 'max:255']),
            self::optionalText('contact_person_phone', '+91-98100-10000'),
            self::optionalText('legal_name', 'Acme Pharma Private Limited'),
            self::optionalText('registration_number', 'U24230MH2012PTC123456'),
            self::optionalText('gst_number', '27AAAAA0000A1Z5'),
            self::optionalDate('registration_date', '2012-04-01'),
            self::optionalText('legal_status', 'Private Limited'),
            ImportColumn::make('parent_organization')
                ->label('Parent organization')
                ->ignoreBlankState()
                ->example('Acme Group')
                ->helperText('Matched by organization name. A missing parent is created so its own row can fill in the details.')
                ->rules(function (array $data): array {
                    return [
                        function (string $attribute, mixed $value, Closure $fail) use ($data): void {
                            if (filled($value) && $value === ($data['name'] ?? null)) {
                                $fail('An organization cannot be its own parent.');
                            }
                        },
                    ];
                })
                ->fillRecordUsing(function (Organization $record, ?string $state): void {
                    if (blank($state) || $state === $record->name) {
                        return;
                    }

                    $parent = Organization::query()->firstOrCreate(
                        ['name' => $state],
                        ['status' => 'active'],
                    );

                    $record->parentOrganization()->associate($parent);
                }),
            self::optionalText('size', '500-1000'),
            ImportColumn::make('annual_revenue')
                ->numeric(decimalPlaces: 2)
                ->ignoreBlankState()
                ->rules(['nullable', 'numeric', 'min:0'])
                ->example('2500000.50')
                ->castStateUsing(function (mixed $state, mixed $originalState): mixed {
                    if (blank($originalState)) {
                        return null;
                    }

                    $normalized = str_replace([',', ' '], '', (string) $originalState);

                    if (! is_numeric($normalized)) {
                        return $originalState;
                    }

                    return $state;
                }),
            self::optionalText('operation_hours', 'Mon-Sat, 9 AM - 6 PM'),
            ImportColumn::make('timezone')
                ->ignoreBlankState()
                ->rules(['nullable', Rule::in(DateTimeZone::listIdentifiers())])
                ->example('Asia/Kolkata'),
            self::optionalText('language', 'English'),
            self::optionalText('linkedin_url', 'https://linkedin.com/company/acme-pharma', ['nullable', 'url', 'max:255']),
            self::optionalText('twitter_url', 'https://twitter.com/acmepharma', ['nullable', 'url', 'max:255']),
            self::optionalText('facebook_url', 'https://facebook.com/acmepharma', ['nullable', 'url', 'max:255']),
            self::optionalText('instagram_url', 'https://instagram.com/acmepharma', ['nullable', 'url', 'max:255']),
            ImportColumn::make('status')
                ->ignoreBlankState()
                ->rules(['nullable', Rule::in(['active', 'inactive', 'pending'])])
                ->example('active')
                ->castStateUsing(function (mixed $state): ?string {
                    if (blank($state)) {
                        return null;
                    }

                    return strtolower((string) $state);
                }),
            ImportColumn::make('description')
                ->ignoreBlankState()
                ->rules(['nullable', 'string'])
                ->example('Field force organization.'),
            ImportColumn::make('metadata')
                ->ignoreBlankState()
                ->example('{"code":"ACME"}')
                ->helperText('JSON object of extra values.')
                ->rules([
                    'nullable',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! is_array($value)) {
                            $fail('Metadata must be a JSON object, for example {"code":"ACME"}.');
                        }
                    },
                ])
                ->castStateUsing(function (mixed $state): mixed {
                    if (blank($state)) {
                        return null;
                    }

                    if (! is_string($state)) {
                        return $state;
                    }

                    $decoded = json_decode($state, true);

                    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                        return $state;
                    }

                    return $decoded;
                }),
        ];
    }

    public function resolveRecord(): Organization
    {
        return Organization::query()->firstOrNew([
            'name' => $this->data['name'] ?? null,
        ]);
    }

    protected function beforeCreate(): void
    {
        if (blank($this->record->status)) {
            $this->record->status = 'active';
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your organization import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private static function optionalText(string $name, string $example, array $rules = ['nullable', 'max:255']): ImportColumn
    {
        return ImportColumn::make($name)
            ->ignoreBlankState()
            ->rules($rules)
            ->example($example);
    }

    private static function optionalDate(string $name, string $example): ImportColumn
    {
        return ImportColumn::make($name)
            ->ignoreBlankState()
            ->rules(['nullable', 'date'])
            ->example($example);
    }
}
