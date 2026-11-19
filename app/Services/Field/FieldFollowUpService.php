<?php

namespace App\Services\Field;

use App\Models\ContactDetail;
use App\Models\FollowUp;
use App\Models\FollowUpMedia;
use App\Models\FollowUpPriority;
use App\Models\FollowUpResult;
use App\Models\FollowUpStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\DateFormatService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldFollowUpService
{
    public function __construct(private FieldLeadService $leads) {}

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'user:id,name',
            'contactDetail',
            'media:id,name',
            'result:id,name',
            'status:id,name',
            'priority:id,name',
            'followupable',
        ];
    }

    /**
     * Visible follow-ups; with [$leadId] the lead must also be visible.
     *
     * @return LengthAwarePaginator<int, FollowUp>
     */
    public function list(User $user, ?string $search = null, ?int $leadId = null, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless($user->can('viewAny', FollowUp::class), 403);

        $query = FollowUp::query()
            ->with($this->relations())
            ->applyVisibility('FollowUp', $user)
            ->orderByDesc('follow_up_date')
            ->orderByDesc('id');

        if ($leadId !== null) {
            $lead = $this->leads->findVisible($user, $leadId);
            $query->where('followupable_type', Lead::class)
                ->where('followupable_id', $lead->id);
        }

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereLike('interaction', $term)
                    ->orWhereLike('outcome', $term);
            });
        }

        return $query->paginate($perPage > 0 ? min($perPage, 100) : 50);
    }

    public function findVisible(User $user, int $followUpId): FollowUp
    {
        abort_unless($user->can('viewAny', FollowUp::class), 403);

        $followUp = FollowUp::query()
            ->with($this->relations())
            ->applyVisibility('FollowUp', $user)
            ->find($followUpId);

        abort_if($followUp === null, 404, 'Follow-up not found.');
        abort_unless($user->can('view', $followUp), 403);

        return $followUp;
    }

    public function canUpdate(User $user, FollowUp $followUp): bool
    {
        return $user->can('update', $followUp);
    }

    /**
     * @return array{
     *     can_create: bool,
     *     media: Collection<int, FollowUpMedia>,
     *     results: Collection<int, FollowUpResult>,
     *     statuses: Collection<int, FollowUpStatus>,
     *     priorities: Collection<int, FollowUpPriority>
     * }
     */
    public function options(User $user): array
    {
        abort_unless($user->can('viewAny', FollowUp::class), 403);

        return [
            'can_create' => $user->can('create', FollowUp::class),
            'media' => FollowUpMedia::query()->orderBy('name')->get(['id', 'name']),
            'results' => FollowUpResult::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => FollowUpStatus::query()->orderBy('name')->get(['id', 'name']),
            'priorities' => FollowUpPriority::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * The caller is always the assignee, as in the Admin lead follow-up form.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(User $user, array $data): FollowUp
    {
        abort_unless($user->can('create', FollowUp::class), 403);

        $lead = $this->leads->findVisible($user, (int) $data['lead_id']);

        if (isset($data['to_whom'])) {
            $this->assertContact($user, $lead, (int) $data['to_whom']);
        }

        $followUp = DB::transaction(function () use ($user, $lead, $data): FollowUp {
            $followUp = FollowUp::query()->create([
                ...collect($data)->only($this->writableFields())->all(),
                'followupable_type' => Lead::class,
                'followupable_id' => $lead->id,
                'user_id' => $user->id,
            ]);

            $this->logLeadActivity($user, $lead, $followUp, 'Follow-up Created');

            return $followUp;
        });

        return $followUp->fresh($this->relations());
    }

    /**
     * Omitted keys keep their stored values; an unchanged contact is not re-checked.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, FollowUp $followUp, array $data): FollowUp
    {
        abort_unless($user->can('update', $followUp), 403);

        $lead = $followUp->followupable instanceof Lead ? $followUp->followupable : null;

        if (isset($data['to_whom']) && (int) $data['to_whom'] !== (int) $followUp->to_whom) {
            if ($lead === null) {
                throw ValidationException::withMessages([
                    'to_whom' => 'Contacts can only be chosen for lead follow-ups.',
                ]);
            }

            $this->assertContact($user, $lead, (int) $data['to_whom']);
        }

        DB::transaction(function () use ($user, $followUp, $lead, $data): void {
            $followUp->fill(collect($data)->only($this->writableFields())->all())->save();

            if ($lead !== null) {
                $this->logLeadActivity($user, $lead, $followUp, 'Follow-up Updated');
            }
        });

        return $followUp->fresh($this->relations());
    }

    /**
     * @return list<string>
     */
    private function writableFields(): array
    {
        return [
            'follow_up_date',
            'next_follow_up_date',
            'to_whom',
            'interaction',
            'outcome',
            'follow_up_media_id',
            'follow_up_result_id',
            'follow_up_priority_id',
            'follow_up_status_id',
        ];
    }

    /**
     * Allowed: the lead's contact, a contact on the lead's account, or a contact the user can see.
     */
    private function assertContact(User $user, Lead $lead, int $contactId): void
    {
        if ($contactId === (int) $lead->contact_detail_id) {
            return;
        }

        $contact = ContactDetail::query()->find($contactId);

        if ($contact !== null && $lead->account_master_id !== null) {
            $accountId = (int) $lead->account_master_id;

            if (
                (int) $contact->account_master_id === $accountId
                || $contact->accountMasters()->whereKey($accountId)->exists()
            ) {
                return;
            }
        }

        if ($contact !== null && ContactDetail::query()->applyVisibility('ContactDetail', $user)->whereKey($contactId)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'to_whom' => 'Select a contact on this lead or one you can access.',
        ]);
    }

    private function logLeadActivity(User $user, Lead $lead, FollowUp $followUp, string $type): void
    {
        $followUp->loadMissing('media:id,name');

        LeadActivity::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'activity_type' => $type,
            'description' => 'Follow-up on '.
                Carbon::parse($followUp->follow_up_date)->format(app(DateFormatService::class)->dateFormat().' h:i A').
                ' using '.($followUp->media->name ?? 'Unknown').' as media type.',
        ]);
    }
}
