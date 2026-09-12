<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission;
use Google\Service\Drive\PermissionList;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use JeffersonGoncalves\FlysystemGoogleDrive\GoogleDriveAdapter;
use JeffersonGoncalves\FlysystemGoogleDrive\Tests\Support\DriveTestFactory;
use League\Flysystem\Config;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;

it('escapes a filename containing a single quote before sending it to the Drive API', function () {
    [$drive, $files] = DriveTestFactory::make();

    $capturedQuery = null;
    $files->shouldReceive('listFiles')
        ->once()
        ->with(Mockery::on(function (array $params) use (&$capturedQuery) {
            $capturedQuery = $params['q'];

            return true;
        }))
        ->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive);

    $adapter->fileExists("Jefferson's backup.zip");

    expect($capturedQuery)->toContain("name = 'Jefferson\\'s backup.zip'");
});

it('does not swallow a real Drive API error as a successful delete', function () {
    // Bug class: only a MISSING path may be reported as a successful
    // delete. A real API failure (e.g. a 403 permission error) must surface.
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList([
        'files' => [new DriveFile(['id' => 'file-1', 'mimeType' => 'text/plain'])],
    ]));
    $files->shouldReceive('delete')->once()->andThrow(new GoogleServiceException('Forbidden', 403));

    $adapter = new GoogleDriveAdapter($drive);

    expect(fn () => $adapter->delete('db.zip'))->toThrow(UnableToDeleteFile::class);
});

it('treats delete of a path that was never created as a no-op success', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList(['files' => []]));
    $files->shouldNotReceive('delete');

    $adapter = new GoogleDriveAdapter($drive);

    expect(fn () => $adapter->delete('does-not-exist.zip'))->not->toThrow(Throwable::class);
});

it('invalidates both the old and new cache entry after a move', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList([
        'files' => [new DriveFile(['id' => 'file-1', 'mimeType' => 'text/plain'])],
    ]));
    $files->shouldReceive('get')->once()->with('file-1', ['fields' => 'parents'])
        ->andReturn(new DriveFile(['parents' => ['root']]));
    $files->shouldReceive('update')->once()->andReturn(new DriveFile(['id' => 'file-1']));

    $adapter = new GoogleDriveAdapter($drive);

    $adapter->move('old.zip', 'new.zip', new Config);

    $cache = (new ReflectionProperty($adapter, 'cache'));
    $cache->setAccessible(true);
    $cache = $cache->getValue($adapter);

    expect($cache->get('old.zip'))->toBeNull()
        ->and($cache->get('new.zip'))->toBe('file-1');
});

it('records the destination in cache after a copy, without touching the source entry', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList([
        'files' => [new DriveFile(['id' => 'file-1', 'mimeType' => 'text/plain'])],
    ]));
    $files->shouldReceive('copy')->once()->andReturn(new DriveFile(['id' => 'file-2']));

    $adapter = new GoogleDriveAdapter($drive);

    $adapter->copy('source.zip', 'destination.zip', new Config);

    $cache = new ReflectionProperty($adapter, 'cache');
    $cache->setAccessible(true);
    $cache = $cache->getValue($adapter);

    expect($cache->get('destination.zip'))->toBe('file-2')
        ->and($cache->get('source.zip'))->toBe('file-1'); // untouched: copying leaves the source alone
});

it('throws when asking for the file size of something that has none, e.g. a folder', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList([
        'files' => [new DriveFile(['id' => 'folder-1', 'mimeType' => 'application/vnd.google-apps.folder'])],
    ]));

    $adapter = new GoogleDriveAdapter($drive);

    expect(fn () => $adapter->fileSize('backups'))->toThrow(UnableToRetrieveMetadata::class);
});

it('round-trips visibility through an "anyone" reader permission', function () {
    [$drive, $files, $permissions] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->twice()->andReturn(new FileList([
        'files' => [new DriveFile(['id' => 'file-1', 'mimeType' => 'text/plain'])],
    ]));
    $permissions->shouldReceive('create')->once()->with('file-1', Mockery::type(Permission::class));
    $permissions->shouldReceive('listPermissions')->once()->andReturn(new PermissionList([
        'permissions' => [new Permission(['type' => 'anyone'])],
    ]));

    $adapter = new GoogleDriveAdapter($drive);
    $adapter->setVisibility('db.zip', Visibility::PUBLIC);

    expect($adapter->visibility('db.zip')->visibility())->toBe(Visibility::PUBLIC);
});

it('resolves the configured folder as a literal Drive ID when it exists', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('get')->once()->with('1abcXYZ', ['fields' => 'id'])
        ->andReturn(new DriveFile(['id' => '1abcXYZ']));
    $files->shouldReceive('listFiles')->once()->andReturn(new FileList(['files' => []]));

    $adapter = new GoogleDriveAdapter($drive, folder: '1abcXYZ');

    expect($adapter->directoryExists('missing'))->toBeFalse();
});

it('writes a zero-byte file as a single multipart request, without the resumable dance', function () {
    [$drive, $files] = DriveTestFactory::make();

    $files->shouldReceive('listFiles')->once()->andReturn(new FileList(['files' => []]));
    $files->shouldReceive('create')
        ->once()
        ->with(Mockery::type(DriveFile::class), Mockery::on(fn (array $p) => $p['uploadType'] === 'multipart'))
        ->andReturn(new DriveFile(['id' => 'empty-file']));

    $adapter = new GoogleDriveAdapter($drive);

    $adapter->write('empty.txt', '', new Config);

    $cache = new ReflectionProperty($adapter, 'cache');
    $cache->setAccessible(true);

    expect($cache->getValue($adapter)->get('empty.txt'))->toBe('empty-file');
});

it('uploads content larger than the chunk size in multiple resumable chunks, never in one shot', function () {
    // The real workload is 37-40MB Postgres dump zips; this proves the
    // upload streams in bounded chunks instead of sending the whole payload
    // (or buffering it) in one request.
    $history = [];
    $mock = new MockHandler([
        // resolve('backup.zip'): the file does not exist yet, so the upload below creates it.
        new Response(200, ['Content-Type' => 'application/json'], json_encode(['files' => []])),
        // The upload itself: open a resumable session, one partial chunk, then the final chunk.
        new Response(200, ['location' => 'https://upload.example.test/resume/abc']),
        new Response(308, ['range' => 'bytes=0-9']),
        new Response(200, [], json_encode(['id' => 'new-file-id'])),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new Client;
    $client->setHttpClient(new GuzzleClient(['handler' => $stack]));
    $client->setAccessToken(['access_token' => 'test-token', 'expires_in' => 3600, 'created' => time()]);

    $drive = new Drive($client);

    // No `folder` configured, and the file lives at the root, so rootFolderId()
    // and ensureDirectoryId('') never call the API - every mocked HTTP
    // response above belongs to the upload itself.
    $adapter = new GoogleDriveAdapter($drive, folder: null, chunkSize: 10);

    $adapter->write('backup.zip', str_repeat('x', 15), new Config);

    expect($history)->toHaveCount(4);

    $firstChunkBody = (string) $history[2]['request']->getBody();
    $secondChunkBody = (string) $history[3]['request']->getBody();

    expect(strlen($firstChunkBody))->toBe(10)
        ->and(strlen($secondChunkBody))->toBe(5);
});
