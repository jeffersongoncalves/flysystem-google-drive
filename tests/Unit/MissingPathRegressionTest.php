<?php

declare(strict_types=1);

use Google\Service\Drive\FileList;
use JeffersonGoncalves\FlysystemGoogleDrive\GoogleDriveAdapter;
use JeffersonGoncalves\FlysystemGoogleDrive\Tests\Support\DriveTestFactory;

/*
 * This is the entire reason this package exists. spatie/laravel-backup's
 * BackupDestination::isReachable() calls Storage::disk('google')->files($path)
 * as a health check BEFORE the very first backup ever uploads anything.
 * masbug/flysystem-google-drive-ext throws UnableToReadFile/UnableToListContents
 * when that folder doesn't exist yet, which makes the very first backup run
 * fail with no way to recover except manually pre-creating the remote folder.
 *
 * listContents() on a path that doesn't exist yet must return an empty
 * listing, and directoryExists() on a missing path must return false -
 * neither may throw.
 */
it('lists an empty result for a path that has never been created, instead of throwing', function () {
    [$drive, $files] = DriveTestFactory::make();

    // Every listFiles() call (resolving the never-created "backups" folder)
    // finds nothing, exactly like a fresh Drive account.
    $files->shouldReceive('listFiles')->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive);

    $contents = iterator_to_array($adapter->listContents('backups', false));

    expect($contents)->toBe([]);
});

it('reports directoryExists() as false for a path that was never created, instead of throwing', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive);

    expect($adapter->directoryExists('backups'))->toBeFalse();
});

it('reports fileExists() as false for a path that was never created, instead of throwing', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive);

    expect($adapter->fileExists('backups/db.zip'))->toBeFalse();
});

it('lists an empty deep result for a nested path that has never been created', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive);

    $contents = iterator_to_array($adapter->listContents('nested/does/not/exist', true));

    expect($contents)->toBe([]);
});
