<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\FlysystemGoogleDrive\GoogleDriveAdapter;

it('registers the google-drive driver automatically, with no manual Storage::extend() call needed', function () {
    config()->set('filesystems.disks.google-drive', [
        'driver' => 'google-drive',
        'clientId' => 'fake-client-id',
        'clientSecret' => 'fake-client-secret',
        'refreshToken' => 'fake-refresh-token',
        'folder' => 'Backups',
    ]);

    $disk = Storage::disk('google-drive');

    expect($disk->getAdapter())->toBeInstanceOf(GoogleDriveAdapter::class);
});
