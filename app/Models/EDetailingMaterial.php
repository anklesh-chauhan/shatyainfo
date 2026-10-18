<?php

namespace App\Models;

use App\Traits\HasBlameable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EDetailingMaterial extends Model
{
    use HasBlameable, HasFactory, SoftDeletes;

    protected $table = 'e_detailing_materials';

    protected $fillable = ['title', 'description', 'item_master_id', 'file_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'file_size' => 'integer', 'sort_order' => 'integer'];
    }

    public static function uploadDirectory(): string
    {
        $tenant = Tenant::current();
        $identity = $tenant ? 'tenant-'.$tenant->getKey() : 'database-'.hash('sha256', (string) config('database.connections.'.config('database.default').'.database'));

        return 'e-detailing/'.$identity;
    }

    protected static function booted(): void
    {
        static::saving(function (self $material): void {
            if (! $material->isDirty('file_path')) {
                return;
            }
            $path = (string) $material->file_path;
            $disk = Storage::disk('local');
            if (! str_starts_with($path, static::uploadDirectory().'/') || str_contains($path, '..') || str_contains($path, '\\') || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['file_path' => 'Upload a file for this company.']);
            }
            $mime = $disk->mimeType($path);
            $size = $disk->size($path);
            if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true) || $size > 10 * 1024 * 1024 || $size === 0) {
                throw ValidationException::withMessages(['file_path' => 'Use a PDF, JPG, or PNG up to 10 MB.']);
            }
            $material->mime_type = $mime;
            $material->file_size = $size;
            $material->content_version = hash_file('sha256', $disk->path($path));
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class, 'item_master_id');
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(EDetailingPresentation::class);
    }
}
