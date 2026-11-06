<?php

use App\Models\Media;
use App\Models\Tenant;
use App\Tasks\SwitchTenantFilesystemTask;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class);

it('serves existing mobile photo previews from the current request origin', function (string $origin) {
    config([
        'app.url' => 'https://shatyainfo.com',
        'filesystems.disks.public.url' => 'https://shatyainfo.com/storage',
    ]);
    URL::setRequest(Request::create($origin.'/admin/visits/12/edit'));
    File::shouldReceive('ensureDirectoryExists')->twice();
    Storage::disk('public');

    $task = new SwitchTenantFilesystemTask;
    $tenant = (new Tenant)->forceFill(['id' => 14, 'domain' => 'hbl.shatyainfo.com']);
    $task->makeCurrent($tenant);

    $path = 'visits/photos/mobile-upload.jpg';
    $media = new Media(['disk' => 'public', 'path' => $path]);
    $field = FileUpload::make('path')->disk('public')->image()->fetchFileInformation(false);
    $expected = $origin.'/storage/tenants/14/'.$path;

    expect($field->getUploadedFile($path, null)['url'])->toBe($expected)
        ->and($media->url)->toBe($expected)
        ->and($media->path)->toBe($path)
        ->and(config('filesystems.disks.public.root'))->toBe(storage_path('app/public/tenants/14'));

    $task->forgetCurrent();

    expect(Storage::disk('public')->url($path))->toBe('https://shatyainfo.com/storage/'.$path);
})->with([
    'company admin' => 'https://hbl.shatyainfo.com',
    'another company admin' => 'https://other.shatyainfo.com',
    'mobile API on main host' => 'https://shatyainfo.com',
    'local development port' => 'http://hbl.test:8080',
]);
