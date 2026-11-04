<?php

namespace App\Services\Field;

use App\Jobs\ExtractSecondaryStatement;
use App\Models\AccountMaster;
use App\Models\SecondaryOcrSource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecondaryOcrService
{
    /**
     * Create a new class instance.
     */
    public function capture(User $user, UploadedFile $file, array $data): SecondaryOcrSource
    {
        abort_unless($user->can('Create:SecondarySales'), 403);
        $stockist = AccountMaster::query()->applyVisibility('AccountMaster')->where('accept_secondary_statements', true)->findOrFail($data['stockist_id']);
        abort_unless($user->can('view', $stockist), 403);
        abort_unless((int) $stockist->territory_id === (int) $data['territory_id'], 422, 'Select the stockist master territory.');
        $reviewer = User::query()->findOrFail($data['reviewer_id']);
        abort_unless($reviewer->id !== $user->id && $reviewer->can('Review:SecondarySales'), 422, 'Select an authorized RSM / Commercial Excellence reviewer.');

        return DB::transaction(function () use ($stockist, $user, $file, $reviewer, $data): SecondaryOcrSource {
            $stockist->newQuery()->whereKey($stockist->id)->lockForUpdate()->firstOrFail();
            $hash = hash_file('sha256', $file->getRealPath());
            $existing = SecondaryOcrSource::query()->where('stockist_id', $stockist->id)->where('source_sha256', $hash)->where('period', $data['period'])->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === (int) $user->id || (int) $existing->reviewer_id === (int) $user->id, 409, 'This scan was already uploaded. Ask the assigned reviewer to reconcile it.');

                return $existing;
            }
            $extension = strtolower($file->getClientOriginalExtension());
            $configured = is_file((string) config('services.secondary_ocr.tesseract_binary'))
                && ($extension !== 'pdf' || is_file((string) config('services.secondary_ocr.pdf_rasterizer_binary')));
            $source = SecondaryOcrSource::query()->create(['stockist_id' => $stockist->id, 'territory_id' => $data['territory_id'],
                'created_by' => $user->id, 'reviewer_id' => $reviewer->id, 'period' => $data['period'],
                'source_sha256' => $hash, 'source_path' => Storage::disk('local')->putFile('secondary-ocr-sources', $file),
                'extension' => $extension, 'extraction_token' => (string) Str::uuid(), 'status' => $configured ? 'queued' : 'awaiting_provider']);
            if ($configured) {
                ExtractSecondaryStatement::dispatch($source->id, $source->extraction_token)->afterCommit();
            }

            return $source;
        });
    }

    public function extract(int $id, ?string $expectedToken = null): void
    {
        $source = DB::transaction(function () use ($id, $expectedToken): ?SecondaryOcrSource {
            $source = SecondaryOcrSource::query()->lockForUpdate()->findOrFail($id);
            if ($source->secondary_sales_statement_id || ! in_array($source->status, ['queued', 'awaiting_provider', 'failed'], true)
                || ($expectedToken !== null && $source->extraction_token !== $expectedToken)) {
                return null;
            }
            $source->update(['status' => 'extracting', 'error' => null, 'extraction_token' => $source->extraction_token ?? (string) Str::uuid(), 'extraction_started_at' => now()]);

            return $source;
        });
        if (! $source) {
            return;
        }
        $disk = Storage::disk('local');
        $work = 'secondary-ocr-work/'.Str::uuid();
        try {
            $binary = (string) config('services.secondary_ocr.tesseract_binary');
            if (! is_file($binary)) {
                $this->updateAttempt($source, ['status' => 'awaiting_provider']);

                return;
            }
            $images = [$disk->path($source->source_path)];
            if ($source->extension === 'pdf') {
                $rasterizer = (string) config('services.secondary_ocr.pdf_rasterizer_binary');
                if (! is_file($rasterizer)) {
                    $this->updateAttempt($source, ['status' => 'awaiting_provider']);

                    return;
                }
                $disk->makeDirectory($work);
                $process = Process::timeout(60)->run([$rasterizer, '-f', '1', '-l', '21', '-r', '150', '-png', $images[0], $disk->path($work.'/page')]);
                if (! $process->successful()) {
                    throw new \RuntimeException('PDF rasterization failed. The source is retained.');
                }
                $images = array_map(fn ($file) => $disk->path($file), array_filter($disk->files($work), fn ($file) => str_ends_with($file, '.png')));
                natsort($images);
                if (count($images) > 20 || $images === []) {
                    throw new \RuntimeException('Use a statement of at most 20 readable PDF pages.');
                }
            }
            $tokens = [];
            $text = [];
            $page = 0;
            foreach ($images as $image) {
                $page++;
                $process = Process::timeout(25)->run([$binary, $image, 'stdout', '-l', 'eng', 'tsv']);
                if (! $process->successful() || strlen($process->output()) > 2 * 1024 * 1024) {
                    throw new \RuntimeException('OCR extraction failed or exceeded its output limit. The source is retained.');
                }
                $lines = [];
                foreach (explode("\n", $process->output()) as $row) {
                    $cells = explode("\t", trim($row), 12);
                    if (count($cells) !== 12 || $cells[0] !== '5' || ! is_numeric($cells[10]) || (float) $cells[10] < 0 || $cells[11] === '') {
                        continue;
                    }
                    $line = implode('-', array_slice($cells, 1, 4));
                    $lines[$line][] = $cells[11];
                    $tokens[] = ['page' => $page, 'line' => $line, 'text' => $cells[11], 'confidence' => min(100, (float) $cells[10]),
                        'left' => (int) $cells[6], 'top' => (int) $cells[7], 'width' => (int) $cells[8], 'height' => (int) $cells[9]];
                }
                foreach ($lines as $words) {
                    $text[] = implode(' ', $words);
                }
            }
            if ($tokens === []) {
                throw new \RuntimeException('No readable words were extracted. Upload a clearer scan or map it manually.');
            }
            DB::transaction(function () use ($id, $source, $text, $tokens): void {
                $fresh = SecondaryOcrSource::query()->lockForUpdate()->findOrFail($id);
                if ($fresh->extraction_token !== $source->extraction_token || ! in_array($fresh->status, ['extracting', 'converted'], true)) {
                    return;
                }
                $fresh->update(['status' => $fresh->secondary_sales_statement_id ? 'converted' : 'needs_mapping', 'extracted_text' => implode("\n", $text),
                    'tokens' => $tokens, 'mean_confidence' => round(array_sum(array_column($tokens, 'confidence')) / count($tokens), 2)]);
            });
        } catch (\Throwable $error) {
            $this->failAttempt($id, $source->extraction_token, $error instanceof \RuntimeException ? $error->getMessage() : 'OCR failed. The original source is retained for review.');
        } finally {
            if ($disk->directoryExists($work)) {
                $disk->deleteDirectory($work);
            }
        }
    }

    public function retry(User $user, SecondaryOcrSource $source): void
    {
        abort_unless((int) $source->created_by === (int) $user->id && $user->can('Create:SecondarySales'), 403);
        DB::transaction(function () use ($source): void {
            $source = SecondaryOcrSource::query()->lockForUpdate()->findOrFail($source->id);
            abort_unless($source->canRetryExtraction(), 409, 'This source is processing or mapped. Abandoned attempts can be recovered after 15 minutes.');
            abort_unless(is_file((string) config('services.secondary_ocr.tesseract_binary'))
                && ($source->extension !== 'pdf' || is_file((string) config('services.secondary_ocr.pdf_rasterizer_binary'))), 422, 'Configure the OCR executables before retrying. The original remains available for manual mapping.');
            $source->update(['status' => 'queued', 'error' => null, 'extraction_token' => (string) Str::uuid(), 'extraction_started_at' => null]);
            ExtractSecondaryStatement::dispatch($source->id, $source->extraction_token)->afterCommit();
        });
    }

    private function updateAttempt(SecondaryOcrSource $source, array $attributes): void
    {
        SecondaryOcrSource::query()->whereKey($source->id)->where('extraction_token', $source->extraction_token)
            ->where('status', 'extracting')->whereNull('secondary_sales_statement_id')->update($attributes);
    }

    public function failAttempt(int $id, string $token, string $message): void
    {
        SecondaryOcrSource::query()->whereKey($id)->where('extraction_token', $token)->whereIn('status', ['queued', 'extracting'])
            ->whereNull('secondary_sales_statement_id')->update(['status' => 'failed', 'error' => $message]);
    }
}
