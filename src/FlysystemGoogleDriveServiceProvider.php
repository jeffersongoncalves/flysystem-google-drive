<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

/**
 * Registers the `google-drive` Flysystem driver automatically.
 *
 * Unlike masbug/flysystem-google-drive-ext, consuming apps don't need to
 * hand-write a Storage::extend() closure in their own AppServiceProvider —
 * this provider is auto-discovered (see composer.json `extra.laravel`) and
 * does it once for everyone.
 */
final class FlysystemGoogleDriveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('google-drive', function ($app, array $config) {
            $client = new Client;
            $client->setClientId($config['clientId'] ?? '');
            $client->setClientSecret($config['clientSecret'] ?? '');
            GoogleDriveAdapter::useRefreshTokenLazily($client, $config['refreshToken'] ?? '');
            $client->addScope(Drive::DRIVE);

            $adapter = new GoogleDriveAdapter(new Drive($client), $config['folder'] ?? null);

            return new LaravelFilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
