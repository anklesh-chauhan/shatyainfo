<?php

namespace App\Filament\Resources\SecondaryOcrSources\Pages;

use App\Filament\Resources\SecondaryOcrSources\SecondaryOcrSourceResource;
use App\Models\AccountMaster;
use App\Models\Territory;
use App\Models\User;
use App\Services\Field\SecondaryOcrService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageSecondaryOcrSources extends ManageRecords
{
    protected static string $resource = SecondaryOcrSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label('Upload scanned statement')->visible(fn () => auth()->user()->can('Create:SecondarySales'))
                ->schema([
                    Select::make('stockist_id')->label('Stockist')->options(fn () => AccountMaster::query()->applyVisibility('AccountMaster')->where('accept_secondary_statements', true)->pluck('name', 'id'))->searchable()->required(),
                    Select::make('territory_id')->label('Stockist territory')->options(fn () => Territory::query()->pluck('name', 'id'))->required(),
                    TextInput::make('period')->label('Statement month (YYYY-MM)')->default(now()->format('Y-m'))->required()->rules(['date_format:Y-m']),
                    Select::make('reviewer_id')->label('RSM / Commercial Excellence reviewer')->options(fn () => User::query()->get()->filter(fn ($user) => $user->id !== auth()->id() && $user->can('Review:SecondarySales'))->pluck('name', 'id'))->required(),
                    FileUpload::make('file')->disk('local')->directory('secondary-ocr-staging')->visibility('private')->maxSize(5120)->required()
                        ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::uuid().'.'.strtolower($file->getClientOriginalExtension())),
                ])->action(function (array $data): void {
                    abort_unless(str_starts_with($data['file'], 'secondary-ocr-staging/') && ! str_contains($data['file'], '..'), 422);
                    $disk = Storage::disk('local');
                    try {
                        $file = new UploadedFile($disk->path($data['file']), basename($data['file']), test: true);
                        Validator::make(['file' => $file], ['file' => ['file', 'extensions:pdf,png,jpg,jpeg', 'mimes:pdf,png,jpg,jpeg', 'max:5120']])->validate();
                        app(SecondaryOcrService::class)->capture(auth()->user(), $file, $data);
                        Notification::make()->title('Original retained for extraction and mapping')->success()->send();
                    } finally {
                        $disk->delete($data['file']);
                    }
                }),
        ];
    }
}
