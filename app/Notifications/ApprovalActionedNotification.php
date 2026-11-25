<?php

namespace App\Notifications;

use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ApprovalActionedNotification extends Notification
{
    public function __construct(public Approval $approval, public string $status, public ?int $stepId = null) {}

    public function via(User $notifiable): array
    {
        return ['database'];
    }

    public function payload(): array
    {
        $reference = $this->approval->submitted_record_summary['document_number'] ?? '#'.$this->approval->approvable_id;
        $module = str(class_basename($this->approval->approvable_type))->headline();
        $companyDomain = Tenant::current()?->domain;
        $url = route('filament.admin.pages.my-approvals');
        if ($companyDomain) {
            $url = (parse_url($url, PHP_URL_SCHEME) ?: 'https').'://'.$companyDomain.route('filament.admin.pages.my-approvals', absolute: false);
        }

        return [
            'title' => $this->status === 'pending' ? 'Approval requested' : 'Request '.$this->status,
            'body' => $this->status === 'pending'
                ? "$module $reference is waiting for your approval."
                : "$module $reference has been {$this->status}.",
            'approval_id' => (string) $this->approval->id,
            'step_id' => (string) ($this->stepId ?? ''),
            'status' => $this->status,
            'screen' => 'my-approvals',
            'company_domain' => (string) ($companyDomain ?? parse_url(config('app.url'), PHP_URL_HOST)),
            'url' => $url,
        ];
    }

    public function toDatabase(User $notifiable): array
    {
        $payload = $this->payload();

        return [
            ...FilamentNotification::make()->title($payload['title'])->body($payload['body'])
                ->icon('heroicon-o-clipboard-document-check')
                ->actions([Action::make('view')->label('My Approvals')->url($payload['url'])->markAsRead()])
                ->getDatabaseMessage(),
            ...$payload,
        ];
    }
}
