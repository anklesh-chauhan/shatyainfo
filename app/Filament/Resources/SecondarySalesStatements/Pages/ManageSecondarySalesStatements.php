<?php

namespace App\Filament\Resources\SecondarySalesStatements\Pages;

use App\Filament\Resources\SecondarySalesStatements\SecondarySalesStatementResource;
use App\Models\AccountMaster;
use App\Models\SecondaryOcrSource;
use App\Models\Territory;
use App\Models\User;
use App\Services\Field\SecondarySalesBalanceService;
use App\Services\Field\SecondarySalesService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageSecondarySalesStatements extends ManageRecords
{
    protected static string $resource = SecondarySalesStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')->label('Download invoice template')
                ->action(fn () => response()->streamDownload(fn () => print (implode(',', SecondarySalesService::HEADERS)."\r\n"), 'secondary-sales-template.csv')),
            Action::make('balanceTemplate')->label('Download stock-balance template')
                ->action(fn () => response()->streamDownload(fn () => print (implode(',', SecondarySalesBalanceService::HEADERS)."\r\n"), 'secondary-sales-balance-template.csv')),
            Action::make('import')->label('Import CSV / Excel')->visible(fn () => auth()->user()->can('Create:SecondarySales'))
                ->schema([
                    Select::make('stockist_id')->label('Stockist')->options(fn () => AccountMaster::query()->applyVisibility('AccountMaster')->where('accept_secondary_statements', true)->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                    Select::make('territory_id')->label('Stockist territory')->options(fn () => Territory::query()->pluck('name', 'id'))->searchable()->required(),
                    TextInput::make('period')->label('Statement month (YYYY-MM)')->default(now()->format('Y-m'))->required()->rules(['date_format:Y-m']),
                    Select::make('ocr_source_id')->label('Original scanned source (optional)')->options(fn () => SecondaryOcrSource::query()->where('created_by', auth()->id())
                        ->where(fn ($query) => $query->whereNull('secondary_sales_statement_id')->orWhereHas('statement', fn ($statement) => $statement->where('status', 'rejected')))
                        ->latest('id')->get()->mapWithKeys(fn ($source) => [$source->id => '#'.$source->id.' · '.$source->period.' · '.$source->status]))->searchable(),
                    Textarea::make('ocr_mapping_note')->label('How were extracted rows checked against the original?')->maxLength(2000)->required(fn ($get) => filled($get('ocr_source_id'))),
                    Select::make('reviewer_id')->label('RSM / Commercial Excellence reviewer')->options(fn () => User::query()->orderBy('name')->get()->filter(fn ($user) => $user->id !== auth()->id() && $user->can('Review:SecondarySales'))->pluck('name', 'id'))->searchable()->required(),
                    FileUpload::make('file')->disk('local')->directory('secondary-import-staging')->visibility('private')->maxSize(5120)->required()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::uuid().'.'.strtolower($file->getClientOriginalExtension())),
                ])->action(function (array $data): void {
                    abort_unless(str_starts_with($data['file'], 'secondary-import-staging/') && ! str_contains($data['file'], '..'), 422);
                    $disk = Storage::disk('local');
                    try {
                        $file = new UploadedFile($disk->path($data['file']), basename($data['file']), test: true);
                        Validator::make(['file' => $file], ['file' => ['file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120']])->validate();
                        app(SecondarySalesService::class)->ingest(auth()->user(), $file, $data);
                        Notification::make()->title('Statement imported for review')->success()->send();
                    } finally {
                        $disk->delete($data['file']);
                    }
                }),
        ];
    }
}
