<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\Visit;
use App\Services\DateFormatService;
use App\Services\ImageWatermarkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessMediaUploadJob implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $mediaId
    ) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if (! $media) {
            return;
        }

        $media->update([
            'processing_status' => 'processing',
        ]);

        $disk = $media->disk ?? 'public';
        $fullPath = storage_path("app/{$disk}/{$media->path}");

        if (! file_exists($fullPath)) {
            $media->update(['processing_status' => 'failed']);

            return;
        }

        // 1️⃣ Extract metadata
        $media->original_name = basename($media->path);
        $media->mime_type = mime_content_type($fullPath);
        $media->size = filesize($fullPath);

        // 2️⃣ Prefer client-provided GPS; fall back to EXIF, then visit check-in
        if ($media->latitude === null || $media->longitude === null) {
            $exif = @exif_read_data($fullPath);

            if ($exif && isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
                $media->latitude ??= $this->convertGps($exif['GPSLatitude']);
                $media->longitude ??= $this->convertGps($exif['GPSLongitude']);
            }
        }

        if (($media->latitude === null || $media->longitude === null) && $media->model instanceof Visit) {
            $media->latitude ??= $media->model->checkin_latitude;
            $media->longitude ??= $media->model->checkin_longitude;
        }

        // 3️⃣ Apply watermark
        ImageWatermarkService::apply($fullPath, [
            'timestamp' => now()->format(app(DateFormatService::class)->dateFormat().' H:i'),
        ]);

        // 4️⃣ Future AI tagging hook
        // $this->runAiTagging($media);

        // 5️⃣ Future embedding hook
        // $this->storeEmbedding($media);

        $media->is_processed = true;
        $media->processed_at = now();
        $media->processing_status = 'completed';

        $media->save();
    }

    protected function convertGps($coordinate)
    {
        $degrees = $this->gpsToNumber($coordinate[0]);
        $minutes = $this->gpsToNumber($coordinate[1]);
        $seconds = $this->gpsToNumber($coordinate[2]);

        return $degrees + ($minutes / 60) + ($seconds / 3600);
    }

    protected function gpsToNumber($coordPart)
    {
        $parts = explode('/', $coordPart);

        return count($parts) === 1
            ? (float) $parts[0]
            : (float) $parts[0] / (float) $parts[1];
    }
}
