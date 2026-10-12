<?php

namespace App\Tasks;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchTenantFilesystemTask implements SwitchTenantTask
{
    public function __construct(
        protected ?string $publicRoot = null,
        protected ?string $publicUrl = null,
        protected ?string $localRoot = null,
    ) {
        $this->publicRoot ??= config('filesystems.disks.public.root');
        $this->publicUrl ??= config('filesystems.disks.public.url');
        $this->localRoot ??= config('filesystems.disks.local.root');
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        $suffix = 'tenants/'.$tenant->getKey();
        $publicRoot = storage_path('app/public/'.$suffix);
        $localRoot = storage_path('app/private/'.$suffix);

        File::ensureDirectoryExists($publicRoot);
        File::ensureDirectoryExists($localRoot);

        config([
            'filesystems.disks.public.root' => $publicRoot,
            'filesystems.disks.public.url' => rtrim((string) config('app.url'), '/').'/storage/'.$suffix,
            'filesystems.disks.local.root' => $localRoot,
        ]);

        $this->forgetDisks();
    }

    public function forgetCurrent(): void
    {
        config([
            'filesystems.disks.public.root' => $this->publicRoot,
            'filesystems.disks.public.url' => $this->publicUrl,
            'filesystems.disks.local.root' => $this->localRoot,
        ]);

        $this->forgetDisks();
    }

    private function forgetDisks(): void
    {
        Storage::forgetDisk('public');
        Storage::forgetDisk('local');
    }
}
