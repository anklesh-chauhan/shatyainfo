<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Widgets\VisitMobileActionBar as WidgetsVisitMobileActionBar;
use App\Filament\Widgets\VisitPreCallBriefingWidget;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\SalespersonAi\VisitVoiceNoteService;
use App\Services\Visit\VisitEnforcementService;
use App\Traits\HasVisitManagement;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditVisit extends EditRecord
{
    use HasVisitManagement;

    protected static string $resource = VisitResource::class;

    protected function prefs(): VisitPreference
    {
        return VisitPreference::current();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VisitPreCallBriefingWidget::make([
                'visitId' => $this->getRecord()->getKey(),
            ]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTitle(): string
    {
        // Fetch the company name from the record
        $customerName = $this->record->primaryCompany()?->name;

        return $customerName ?? 'Visit Details';
    }

    public function getSubheading(): ?string
    {
        $status = $this->record->visit_status;

        if ($status === 'started') {
            return 'This visit is currently in progress. Remember to check out when you are done.';
        }

        if ($status === 'completed') {
            return 'Visit completed';
        }

        if ($this->record->start_time && ! $this->record->end_time) {
            return 'Visit started on '.$this->record->start_time->format('M d, Y \a\t H:i');
        }

        if ($this->record->end_time) {
            return 'Checked out: '.$this->record->end_time->format('H:i');
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        $account = $this->record->primaryCompany();

        return [
            Action::make('voice_note')
                ->label('Voice note')
                ->icon(Heroicon::OutlinedMicrophone)
                ->modalHeading('Call note')
                ->modalDescription('Nothing is saved until you apply this note.')
                ->modalSubmitActionLabel('Apply note')
                ->schema([
                    Textarea::make('transcript')
                        ->label('What happened on this call')
                        ->helperText('Type the note, or paste text from phone dictation. Say tomorrow, next week, in 3 days, or a YYYY-MM-DD date when a follow-up is needed.')
                        ->required()
                        ->rows(4),
                    Toggle::make('schedule_follow_up')
                        ->label('Also schedule the suggested follow-up')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();
                    $record = $this->getRecord();
                    abort_unless($user instanceof User && $record instanceof Visit, 403);

                    $result = app(VisitVoiceNoteService::class)->apply(
                        $user,
                        $record,
                        $data['transcript'],
                        (bool) ($data['schedule_follow_up'] ?? false),
                    );

                    $this->record->refresh();
                    $this->fillForm();

                    Notification::make()
                        ->title($result['follow_up_id'] ? 'Call note saved and follow-up scheduled' : 'Call note saved')
                        ->body($result['draft']['summary'])
                        ->success()
                        ->send();
                }),

            Action::make('check_in')
                ->icon('heroicon-m-arrow-left-on-rectangle') // 👈 Adds the Check-In icon
                ->label('') // 👈 Removes the text label
                ->tooltip('Check In') // 👈 Shows a clean tooltip on hover
                ->visible(fn () => $this->prefs()->enable_check_in
                    && ! $this->record->start_time
                )
                ->action(fn () => $this->dispatch('trigger-punch-in', [
                    'componentId' => $this->getId(), // 👈 Pass current component ID
                ])),

            Action::make('check_out')
                ->icon('heroicon-m-arrow-right-on-rectangle') // 👈 Adds the Check-Out icon
                ->label('') // 👈 Removes the text label
                ->tooltip('Check Out') // 👈 Shows a clean tooltip on hover
                ->visible(fn () => $this->prefs()->enable_check_out
                    && ! $this->record->end_time
                )
                ->action(fn () => $this->dispatch('trigger-punch-out', [
                    'componentId' => $this->getId(),
                ])),

            // 📞 Call Action
            Action::make('call_customer')
                ->label(false)
                ->icon('heroicon-m-phone')
                ->color('success')
                ->url(fn () => $account?->phone_number ? "tel:{$account->phone_number}" : null)
                ->hidden(fn () => blank($account?->phone_number)),

            // ✉️ Email Action
            Action::make('email_customer')
                ->label(false)
                ->icon('heroicon-m-envelope')
                ->color('gray')
                ->url(fn () => $account?->email ? "mailto:{$account->email}" : null)
                ->hidden(fn () => blank($account?->email)),

            Action::make('uploadCheckInImage')
                ->label('Upload Check-In Image')
                ->modalHeading('Upload Check-In Image')
                ->modalSubmitActionLabel('Upload')
                ->form([
                    FileUpload::make('checkin_image')
                        ->image()
                        ->required()
                        ->directory('visits/photos')
                        ->disk('public'),
                ])
                ->action(function (array $data) {
                    $media = $this->record->media()->create([
                        'path' => $data['checkin_image'],
                        'disk' => 'public',
                    ]);

                    $media->attachTagBySlug('check-in');
                    $this->record->refresh();
                    $this->fillForm();

                })
                ->extraAttributes(['style' => 'display: none !important;']),

            Action::make('uploadCheckOutImage')
                ->label('Upload Check-Out Image')
                ->modalHeading('Upload Check-Out Image')
                ->modalSubmitActionLabel('Upload')
                ->form([
                    FileUpload::make('checkout_image')
                        ->image()
                        ->required()
                        ->directory('visits/photos')
                        ->disk('public'),
                ])
                ->action(function (array $data) {

                    $media = $this->record->media()->create([
                        'path' => $data['checkout_image'],
                        'disk' => 'public',
                    ]);

                    $media->attachTagBySlug('check-out');

                    $this->record->refresh();

                    $this->finalizeCheckout();
                })
                ->extraAttributes(['style' => 'display: none !important;']),

            Action::make('uploadGeneralVisitImage')
                ->label('Upload General Visit Image')
                ->modalHeading('Upload General Visit Image')
                ->modalSubmitActionLabel('Upload')
                ->form([
                    FileUpload::make('general_visit')
                        ->image()
                        ->required()
                        ->directory('visits/photos')
                        ->disk('public'),
                ])
                ->action(function (array $data) {

                    $media = $this->record->media()->create([
                        'path' => $data['general_visit'],
                        'disk' => 'public',
                    ]);

                    $media->attachTagBySlug('general-visit');

                    $this->record->refresh();

                })
                ->extraAttributes(['style' => 'display: none !important;']),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            WidgetsVisitMobileActionBar::class,
        ];
    }

    // 2. Add this attribute to "listen" for the event from the widget
    #[On('trigger-save-visit')]
    public function saveFromWidget(): void
    {
        // This executes the built-in Filament save & validation logic
        $this->save();
    }

    #[On('set-image-location')]
    public function setImageLocation($latitude, $longitude, $rowKey): void
    {
        data_set($this->data, "{$rowKey}.latitude", $latitude);
        data_set($this->data, "{$rowKey}.longitude", $longitude);
    }

    protected function afterSave(): void
    {
        $feedbackData = $this->data['feedbacks'] ?? [];

        $this->updateVisitFeedbacks($this->record, $feedbackData);

        $date = $this->form->getState()['next_follow_up_date'] ?? null;

        if ($date !== optional($this->record->nextFollowUp())->next_follow_up_date) {
            $this->record->followUps()->create([
                'user_id' => Auth::id(),
                'follow_up_date' => now(),
                'next_follow_up_date' => $date,
            ]);
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $nextDate = $this->record->followUps()
            ->orderByDesc('id')
            ->value('next_follow_up_date');

        $data['next_follow_up_date'] = $nextDate;

        return $data;
    }

    protected function beforeSave(): void
    {
        try {

            app(VisitEnforcementService::class)
                ->validateBeforeSave($this->record);

        } catch (ValidationException $e) {

            // 🔥 If checkout image required → open modal
            if (array_key_exists('checkout_image_required', $e->errors())) {
                $this->mountAction('uploadCheckOutImage');
                $this->halt();

                return;
            }

            // 🔥 If checkin image required → open modal
            if (array_key_exists('checkin_image_required', $e->errors())) {
                $this->mountAction('uploadCheckInImage');
                $this->halt();

                return;
            }

            // 🔥 If general visit image required → open modal
            if (array_key_exists('require_general_visit_image', $e->errors())) {
                $this->mountAction('uploadGeneralVisitImage');
                $this->halt();

                return;
            }

            // 🔥 Any other validation → show message
            Notification::make()
                ->title(collect($e->errors())->flatten()->first())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    public function doPunchIn($latitude, $longitude)
    {
        try {
            app(VisitEnforcementService::class)
                ->validateCheckIn($latitude, $longitude);

            $this->record->update([
                'start_time' => now(),
                'checkin_latitude' => $latitude,
                'checkin_longitude' => $longitude,
                'visit_status' => 'started',
            ]);

            $this->refreshFormData(['start_time']);

            $service = app(VisitEnforcementService::class);

            if ($service->needsCheckInImage($this->record)) {
                $this->mountAction('uploadCheckInImage');
            }

            Notification::make()->title('Checked In Successfully!')->success()->send();

        } catch (ValidationException $e) {
            Notification::make()
                ->title(collect($e->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    public function doPunchOut($latitude, $longitude)
    {
        try {

            $service = app(VisitEnforcementService::class);

            // Temporarily assign end_time for validation
            $this->record->end_time = now();

            // Save draft checkout info
            $this->record->update([
                'end_time' => now(),
                'checkout_latitude' => $latitude,
                'checkout_longitude' => $longitude,
                'visit_status' => 'checked_out_pending',
            ]);

            // 🔥 If image required → ask now
            if ($service->needsCheckOutImage($this->record)) {
                $this->mountAction('uploadCheckOutImage');

                return;
            }

            // 🔥 Otherwise finalize
            $this->finalizeCheckout();

        } catch (ValidationException $e) {

            Notification::make()
                ->title(collect($e->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }

    protected function finalizeCheckout(): void
    {
        $this->record->update([
            'visit_status' => 'completed',
        ]);

        Notification::make()
            ->title('Checked Out Successfully!')
            ->success()
            ->send();

        redirect()->route('filament.admin.pages.todays-tour');
    }

    #[On('open-upload-checkin-modal')]
    public function openCheckInModal(): void
    {
        $this->mountAction('uploadCheckInImage');
    }

    #[On('open-upload-checkout-modal')]
    public function openCheckOutModal(): void
    {
        $this->mountAction('uploadCheckOutImage');
    }
}
