<?php

declare(strict_types=1);

use JeffersonGoncalves\FlysystemGoogleDrive\GoogleDriveAdapter;
use League\Flysystem\Config;

/*
 * Sanity-checks the adapter against a REAL Google Drive account. Skips
 * cleanly (rather than failing CI) whenever real credentials aren't
 * present in the environment - the default test suite never needs these.
 *
 * To run it locally:
 *   GOOGLE_DRIVE_CLIENT_ID=... GOOGLE_DRIVE_CLIENT_SECRET=... \
 *   GOOGLE_DRIVE_REFRESH_TOKEN=... GOOGLE_DRIVE_FOLDER=... \
 *   vendor/bin/pest tests/Integration
 */
beforeEach(function () {
    $required = ['GOOGLE_DRIVE_CLIENT_ID', 'GOOGLE_DRIVE_CLIENT_SECRET', 'GOOGLE_DRIVE_REFRESH_TOKEN'];

    foreach ($required as $variable) {
        if (getenv($variable) === false) {
            $this->markTestSkipped("Set {$variable} to run the real-Drive integration suite.");
        }
    }

    $this->adapter = GoogleDriveAdapter::fromCredentials(
        getenv('GOOGLE_DRIVE_CLIENT_ID'),
        getenv('GOOGLE_DRIVE_CLIENT_SECRET'),
        getenv('GOOGLE_DRIVE_REFRESH_TOKEN'),
        getenv('GOOGLE_DRIVE_FOLDER') ?: null,
    );
});

it('writes, reads back, and deletes a real file on Drive', function () {
    $path = 'flysystem-google-drive-integration-test-'.bin2hex(random_bytes(4)).'.txt';

    $this->adapter->write($path, 'hello from the integration suite', new Config);

    expect($this->adapter->fileExists($path))->toBeTrue()
        ->and($this->adapter->read($path))->toBe('hello from the integration suite');

    $this->adapter->delete($path);

    expect($this->adapter->fileExists($path))->toBeFalse();
});

it('lists an empty result for a folder that does not exist on this account', function () {
    $missing = 'this-folder-should-never-exist-'.bin2hex(random_bytes(4));

    expect(iterator_to_array($this->adapter->listContents($missing, false)))->toBe([])
        ->and($this->adapter->directoryExists($missing))->toBeFalse();
});
