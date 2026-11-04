<?php

namespace App\Services\Field;

use App\Models\AccountMaster;
use App\Models\SecondarySalesCorrection;
use App\Models\SecondarySalesStatement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audited reversal and replacement of approved secondary-sales statements.
 *
 * The approved original is never edited or deleted. Approval of a correction marks it `reversed`,
 * releases its invoice/batch groups and physical-adjustment keys, and (for replacements) approves the
 * corrected statement in the same transaction so the verified ledger never double-counts.
 */
class SecondarySalesCorrectionService
{
    public function __construct(private SecondarySalesService $statements) {}

    /**
     * @param  array{kind: string, reason: string, reviewer_id: int}  $data
     */
    public function request(User $user, SecondarySalesStatement $original, array $data, ?UploadedFile $file): SecondarySalesCorrection
    {
        abort_unless($user->can('Create:SecondarySales'), 403);
        abort_unless((int) $original->created_by === (int) $user->id || (int) $original->reviewer_id === (int) $user->id, 404);
        $stockist = AccountMaster::query()->applyVisibility('AccountMaster')->findOrFail($original->stockist_id);
        abort_unless($user->can('view', $stockist), 403);
        $reviewer = User::query()->findOrFail($data['reviewer_id']);
        abort_unless($reviewer->id !== $user->id && $reviewer->can('Review:SecondarySales'), 422, 'Select an authorized RSM or Commercial Excellence reviewer other than yourself.');
        $kind = $data['kind'];
        $source = null;
        if ($kind === 'replacement') {
            abort_unless($file instanceof UploadedFile, 422, 'Attach the corrected statement file for a replacement.');
            $source = $this->statements->readSource($file);
            abort_if(hash_equals($original->source_sha256, $source['hash']), 422, 'The corrected file is identical to the approved source. Upload the corrected statement or request a reversal.');
        }
        $path = null;
        try {
            return DB::transaction(function () use ($user, $original, $stockist, $reviewer, $kind, $data, $file, $source, &$path): SecondarySalesCorrection {
                $stockist->newQuery()->whereKey($stockist->id)->lockForUpdate()->firstOrFail();
                $original = SecondarySalesStatement::query()->lockForUpdate()->findOrFail($original->id);
                abort_unless($original->status === 'approved', 409, 'Only approved statements can be reversed or replaced. Correct a reversed statement through its replacement.');
                abort_if(SecondarySalesCorrection::query()->where('secondary_sales_statement_id', $original->id)->where('status', 'pending_review')->exists(), 409, 'A correction for this statement is already awaiting review.');
                $replacement = null;
                if ($kind === 'replacement') {
                    $replacement = $this->statements->createStatement($user, $stockist, $reviewer, $file, (int) $original->territory_id, $original->period, $source['hash'], $source['rows'], $path, 'replacement_pending', $original->id, $source['kind']);
                }

                return SecondarySalesCorrection::query()->create(['secondary_sales_statement_id' => $original->id, 'replacement_statement_id' => $replacement?->id,
                    'kind' => $kind, 'status' => 'pending_review', 'requested_by' => $user->id, 'reviewer_id' => $reviewer->id, 'reason' => $data['reason'],
                    'original_summary' => $this->statements->statementSummary($original),
                    'replacement_summary' => $replacement ? $this->statements->statementSummary($replacement) : null, 'requested_at' => now()]);
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    /**
     * @param  array<int, int>  $verifiedIds
     */
    public function review(User $user, SecondarySalesCorrection $correction, string $decision, string $note, array $verifiedIds = []): SecondarySalesCorrection
    {
        abort_unless($user->can('Review:SecondarySales') && (int) $correction->reviewer_id === (int) $user->id && (int) $correction->requested_by !== (int) $user->id, 403);

        return DB::transaction(function () use ($user, $correction, $decision, $note, $verifiedIds): SecondarySalesCorrection {
            $original = SecondarySalesStatement::query()->lockForUpdate()->findOrFail($correction->secondary_sales_statement_id);
            AccountMaster::query()->whereKey($original->stockist_id)->lockForUpdate()->firstOrFail();
            $correction = SecondarySalesCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            abort_unless((int) $correction->reviewer_id === (int) $user->id && $user->can('Review:SecondarySales'), 403);
            abort_if($correction->status !== 'pending_review', 409, 'This correction has already been reviewed.');
            abort_unless((int) $original->created_by !== (int) $user->id, 403, 'The statement uploader cannot approve its own correction.');
            $replacement = $correction->replacement_statement_id ? SecondarySalesStatement::query()->lockForUpdate()->findOrFail($correction->replacement_statement_id) : null;
            if ($decision === 'rejected') {
                $replacement?->update(['status' => 'rejected', 'review_note' => $note, 'reviewed_at' => now()]);
                $correction->update(['status' => 'rejected', 'review_note' => $note, 'reviewed_at' => now()]);

                return $correction->load(['statement', 'replacement']);
            }
            abort_unless($original->status === 'approved', 409, 'The original statement is no longer approved; this correction cannot be applied.');
            abort_if($correction->original_summary != $this->statements->statementSummary($original), 409, 'The approved statement changed since this correction was requested. Request it again.');
            $groups = DB::table('secondary_sales_groups')->where('secondary_sales_statement_id', $original->id)->pluck('group_key')->all();
            $adjustments = DB::table('secondary_sales_adjustments')->join('secondary_sales_lines as line', 'line.id', '=', 'secondary_sales_adjustments.secondary_sales_line_id')
                ->where('line.secondary_sales_statement_id', $original->id)->pluck('secondary_sales_adjustments.adjustment_key')->all();
            DB::table('secondary_sales_groups')->where('secondary_sales_statement_id', $original->id)->delete();
            DB::table('secondary_sales_adjustments')->whereIn('adjustment_key', $adjustments)->delete();
            $original->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by_correction_id' => $correction->id]);
            if ($replacement) {
                abort_unless($replacement->status === 'replacement_pending', 409, 'The corrected statement is no longer awaiting review.');
                $this->statements->approveLines($replacement, $verifiedIds);
                $replacement->update(['status' => 'approved', 'review_note' => $note, 'reviewed_at' => now()]);
            }
            $correction->update(['status' => 'approved', 'review_note' => $note, 'reviewed_at' => now(),
                'released_groups' => array_values($groups), 'released_adjustments' => array_values($adjustments),
                'replacement_summary' => $replacement ? $this->statements->statementSummary($replacement) : null]);

            return $correction->load(['statement', 'replacement']);
        });
    }

    public function canRequest(User $user, SecondarySalesStatement $statement): bool
    {
        return $statement->status === 'approved' && $user->can('Create:SecondarySales')
            && ((int) $statement->created_by === (int) $user->id || (int) $statement->reviewer_id === (int) $user->id)
            && ! SecondarySalesCorrection::query()->where('secondary_sales_statement_id', $statement->id)->where('status', 'pending_review')->exists();
    }

    public function canReview(User $user, SecondarySalesCorrection $correction): bool
    {
        return $correction->status === 'pending_review' && $user->can('Review:SecondarySales')
            && (int) $correction->reviewer_id === (int) $user->id && (int) $correction->requested_by !== (int) $user->id;
    }
}
