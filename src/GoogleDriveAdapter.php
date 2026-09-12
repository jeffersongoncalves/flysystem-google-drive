<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive;

use Closure;
use Generator;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Exception as GoogleServiceException;
use JeffersonGoncalves\FlysystemGoogleDrive\Support\DriveQuery;
use JeffersonGoncalves\FlysystemGoogleDrive\Support\PathIdCache;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use RuntimeException;
use Throwable;

/**
 * A League Flysystem v3 adapter for Google Drive.
 *
 * Drive is ID-based, not path-based: every virtual path has to be resolved by
 * walking its segments through the API. resolve() does that walk and, unlike
 * the adapter this package replaces, returns null for a path that simply
 * doesn't exist yet instead of throwing — that's what makes listContents()
 * and directoryExists() safe to call on a folder that was never created.
 */
final class GoogleDriveAdapter implements FilesystemAdapter
{
    private const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    private const DEFAULT_CHUNK_SIZE = 5 * 1024 * 1024;

    private ?string $rootId = null;

    private readonly PathIdCache $cache;

    private readonly FinfoMimeTypeDetector $mimeTypeDetector;

    public function __construct(
        private readonly Drive $service,
        private readonly ?string $folder = null,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        $this->cache = new PathIdCache;
        $this->mimeTypeDetector = new FinfoMimeTypeDetector;
    }

    /**
     * Build an adapter directly from OAuth credentials, without having to
     * construct a \Google\Client by hand first.
     */
    public static function fromCredentials(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        ?string $folder = null,
    ): self {
        $client = new Client;
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        self::useRefreshTokenLazily($client, $refreshToken);
        $client->addScope(Drive::DRIVE);

        return new self(new Drive($client), $folder);
    }

    /**
     * Configure $client to refresh $refreshToken lazily, on the first real
     * API request, instead of eagerly exchanging it for an access token over
     * the network right away.
     *
     * $client->refreshToken() (an alias for fetchAccessTokenWithRefreshToken())
     * would do that exchange immediately and synchronously. Marking the
     * token as already-expired instead makes Google\Client::authorize()
     * attach lazy refresh-credentials middleware, so the network round trip
     * only happens once a request actually needs to go out - which is also
     * what keeps constructing an adapter/disk cheap and side-effect-free in
     * tests.
     */
    public static function useRefreshTokenLazily(Client $client, string $refreshToken): void
    {
        $client->setAccessToken([
            'access_token' => '',
            'refresh_token' => $refreshToken,
            'expires_in' => 0,
            'created' => 0,
        ]);
    }

    public function fileExists(string $path): bool
    {
        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw UnableToCheckFileExistence::forLocation($path, $e);
        }

        return $resolved !== null && $resolved['mimeType'] !== self::FOLDER_MIME_TYPE;
    }

    public function directoryExists(string $path): bool
    {
        $path = $this->normalizePath($path);

        if ($path === '') {
            return true;
        }

        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $e);
        }

        return $resolved !== null && $resolved['mimeType'] === self::FOLDER_MIME_TYPE;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->normalizePath($path);

        if ($path === '') {
            throw UnableToWriteFile::atLocation($path, 'Cannot write to the root path.');
        }

        // ponytail: copy into a fresh, sized, seekable buffer regardless of
        // what kind of resource we were handed. php://temp only spills to
        // disk past ~2MB, so this stays well clear of buffering the 37-40MB
        // backup archives fully into RAM.
        $buffer = fopen('php://temp/maxmemory:2097152', 'r+b');
        stream_copy_to_stream($contents, $buffer);
        rewind($buffer);
        $size = (int) (fstat($buffer)['size'] ?? 0);

        $name = basename($path);

        try {
            $parentId = $this->ensureDirectoryId($this->dirnameOf($path));
            $existing = $this->resolve($path);
            $mimeType = $config->get('mimetype') ?? $this->mimeTypeDetector->detectMimeTypeFromPath($name) ?? 'application/octet-stream';

            $fileId = $this->upload($existing['id'] ?? null, $parentId, $name, $buffer, $mimeType, $size);

            $this->cache->put($path, $fileId);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        } finally {
            fclose($buffer);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not read stream contents.');
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $path = $this->normalizePath($path);

        try {
            $resolved = $this->resolve($path);

            if ($resolved === null) {
                throw new RuntimeException('File not found.');
            }

            // With alt=media, google/apiclient returns the raw PSR-7
            // response instead of a decoded DriveFile (see
            // Google\Service\Resource::call()), so this streams the Guzzle
            // response body rather than buffering the whole download. We
            // deliberately use the API client's own request signing here
            // instead of following a raw download-redirect by hand, which
            // sidesteps the bearer-token-leaked-to-a-redirect-host bug class.
            $response = $this->service->files->get($resolved['id'], ['alt' => 'media']);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        $stream = $response->getBody()->detach();

        if ($stream === null) {
            throw UnableToReadFile::fromLocation($path, 'Empty response body.');
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        $path = $this->normalizePath($path);

        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }

        if ($resolved === null) {
            // A missing path is a no-op success. A real API failure below is
            // not — it must surface, never be swallowed as "deleted".
            return;
        }

        try {
            $this->service->files->delete($resolved['id']);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }

        $this->cache->forgetTree($path);
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalizePath($path);

        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }

        if ($resolved === null) {
            return;
        }

        try {
            // Deleting a folder ID cascades to every descendant on Drive's
            // side, so there is no need to walk and delete children by hand.
            $this->service->files->delete($resolved['id']);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }

        $this->cache->forgetTree($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->ensureDirectoryId($this->normalizePath($path));
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $resolved = $this->requireResolved(
            $path,
            fn (Throwable $e) => UnableToSetVisibility::atLocation($path, $e->getMessage(), $e)
        );

        try {
            if ($visibility === Visibility::PUBLIC) {
                $this->service->permissions->create($resolved['id'], new Permission([
                    'type' => 'anyone',
                    'role' => 'reader',
                ]));

                return;
            }

            $permissions = $this->service->permissions->listPermissions($resolved['id'], [
                'fields' => 'permissions(id,type)',
            ]);

            foreach ($permissions->getPermissions() ?? [] as $permission) {
                if ($permission->getType() === 'anyone') {
                    $this->service->permissions->delete($resolved['id'], $permission->getId());
                }
            }
        } catch (Throwable $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $resolved = $this->requireResolved(
            $path,
            fn (Throwable $e) => UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e)
        );

        try {
            $permissions = $this->service->permissions->listPermissions($resolved['id'], [
                'fields' => 'permissions(type)',
            ]);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }

        $isPublic = false;

        foreach ($permissions->getPermissions() ?? [] as $permission) {
            if ($permission->getType() === 'anyone') {
                $isPublic = true;

                break;
            }
        }

        return new FileAttributes($path, visibility: $isPublic ? Visibility::PUBLIC : Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $resolved = $this->requireResolved(
            $path,
            fn (Throwable $e) => UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e)
        );

        return new FileAttributes($path, mimeType: $resolved['mimeType']);
    }

    public function lastModified(string $path): FileAttributes
    {
        $resolved = $this->requireResolved(
            $path,
            fn (Throwable $e) => UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e)
        );

        return new FileAttributes($path, lastModified: $this->toTimestamp($resolved['modifiedTime']));
    }

    public function fileSize(string $path): FileAttributes
    {
        $resolved = $this->requireResolved(
            $path,
            fn (Throwable $e) => UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e)
        );

        if ($resolved['size'] === null) {
            throw UnableToRetrieveMetadata::fileSize(
                $path,
                'This file has no size (it may be a native Google Docs/Sheets/Slides file, or a folder).'
            );
        }

        return new FileAttributes($path, fileSize: $resolved['size']);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->normalizePath($path);

        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }

        if ($resolved === null) {
            // Regression fix: listing a path that doesn't exist yet returns
            // an empty listing, it never throws. spatie/laravel-backup calls
            // this to health-check a destination before ever uploading
            // anything, so throwing here breaks the very first backup run.
            return;
        }

        try {
            yield from $this->listChildren($resolved['id'], $path, $deep);
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $deep, $e);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizePath($source);
        $destination = $this->normalizePath($destination);

        try {
            $resolved = $this->resolve($source);

            if ($resolved === null) {
                throw UnableToMoveFile::fromLocationTo($source, $destination);
            }

            $newParentId = $this->ensureDirectoryId($this->dirnameOf($destination));

            $current = $this->service->files->get($resolved['id'], ['fields' => 'parents']);
            $oldParents = implode(',', $current->getParents() ?? []);

            $this->service->files->update($resolved['id'], new DriveFile([
                'name' => basename($destination),
            ]), [
                'addParents' => $newParentId,
                'removeParents' => $oldParents,
                'fields' => 'id,parents',
            ]);
        } catch (UnableToMoveFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }

        // Invalidate both the old and new path (and every descendant, if
        // this was a folder) so nothing resolves against a stale entry.
        $this->cache->move($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->normalizePath($source);
        $destination = $this->normalizePath($destination);

        try {
            $resolved = $this->resolve($source);

            if ($resolved === null) {
                throw UnableToCopyFile::fromLocationTo($source, $destination);
            }

            $newParentId = $this->ensureDirectoryId($this->dirnameOf($destination));

            $copied = $this->service->files->copy($resolved['id'], new DriveFile([
                'name' => basename($destination),
                'parents' => [$newParentId],
            ]), ['fields' => 'id']);
        } catch (UnableToCopyFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }

        $this->cache->put($destination, (string) $copied->getId());
    }

    /**
     * Resolve a path to its Drive metadata, or null if any segment along the
     * way doesn't exist. Never throws for "not found" — only for a genuine
     * API failure (bad auth, permission denied, ...), which callers wrap
     * into the appropriate Flysystem exception.
     *
     * @return array{id: string, mimeType: string, modifiedTime: ?string, size: ?int}|null
     */
    private function resolve(string $path): ?array
    {
        $path = $this->normalizePath($path);

        if ($path === '') {
            return [
                'id' => $this->rootFolderId(),
                'mimeType' => self::FOLDER_MIME_TYPE,
                'modifiedTime' => null,
                'size' => null,
            ];
        }

        $segments = explode('/', $path);
        $lastIndex = array_key_last($segments);
        $parentId = $this->rootFolderId();
        $currentPath = '';
        $metadata = null;

        foreach ($segments as $index => $segment) {
            $currentPath = $currentPath === '' ? $segment : "{$currentPath}/{$segment}";
            $isLast = $index === $lastIndex;

            // Intermediate segments are cheap to skip via cache; the final
            // segment is always re-fetched so its metadata is fresh.
            $cachedId = $this->cache->get($currentPath);
            if ($cachedId !== null && ! $isLast) {
                $parentId = $cachedId;

                continue;
            }

            $child = $this->findChild($parentId, $segment, onlyFolders: ! $isLast);

            if ($child === null) {
                return null;
            }

            $this->cache->put($currentPath, $child['id']);
            $parentId = $child['id'];
            $metadata = $child;
        }

        return $metadata;
    }

    /**
     * Find-or-create every folder along $path, returning the final folder's
     * ID. Mirrors Flysystem's "create parent directories implicitly"
     * convention for writes.
     */
    private function ensureDirectoryId(string $path): string
    {
        $path = $this->normalizePath($path);

        if ($path === '') {
            return $this->rootFolderId();
        }

        $segments = explode('/', $path);
        $parentId = $this->rootFolderId();
        $currentPath = '';

        foreach ($segments as $segment) {
            $currentPath = $currentPath === '' ? $segment : "{$currentPath}/{$segment}";

            $id = $this->cache->get($currentPath);

            if ($id === null) {
                $found = $this->findChild($parentId, $segment, onlyFolders: true);
                $id = $found['id'] ?? $this->createFolder($parentId, $segment);
            }

            $this->cache->put($currentPath, $id);
            $parentId = $id;
        }

        return $parentId;
    }

    /**
     * Resolve the configured root scope (config `folder`, a Drive folder ID
     * or name) to an actual folder ID, once per adapter instance.
     */
    private function rootFolderId(): string
    {
        if ($this->rootId !== null) {
            return $this->rootId;
        }

        if ($this->folder === null || $this->folder === '') {
            return $this->rootId = 'root';
        }

        try {
            $file = $this->service->files->get($this->folder, ['fields' => 'id']);

            return $this->rootId = (string) $file->getId();
        } catch (GoogleServiceException) {
            // Not a resolvable file ID: fall through and treat it as a
            // folder name directly under Drive's root instead.
        }

        $child = $this->findChild('root', $this->folder, onlyFolders: true);

        return $this->rootId = $child['id'] ?? $this->createFolder('root', $this->folder);
    }

    /**
     * @return array{id: string, mimeType: string, modifiedTime: ?string, size: ?int}|null
     */
    private function findChild(string $parentId, string $name, bool $onlyFolders): ?array
    {
        $conditions = [
            sprintf("'%s' in parents", DriveQuery::escape($parentId)),
            sprintf("name = '%s'", DriveQuery::escape($name)),
            'trashed = false',
        ];

        if ($onlyFolders) {
            $conditions[] = sprintf("mimeType = '%s'", self::FOLDER_MIME_TYPE);
        }

        $result = $this->service->files->listFiles([
            'q' => implode(' and ', $conditions),
            'fields' => 'files(id,mimeType,modifiedTime,size)',
            'spaces' => 'drive',
            'pageSize' => 1,
        ]);

        $file = $result->getFiles()[0] ?? null;

        if ($file === null) {
            return null;
        }

        return [
            'id' => (string) $file->getId(),
            'mimeType' => (string) $file->getMimeType(),
            'modifiedTime' => $file->getModifiedTime(),
            'size' => $file->getSize() !== null ? (int) $file->getSize() : null,
        ];
    }

    private function createFolder(string $parentId, string $name): string
    {
        $created = $this->service->files->create(new DriveFile([
            'name' => $name,
            'mimeType' => self::FOLDER_MIME_TYPE,
            'parents' => [$parentId],
        ]), ['fields' => 'id']);

        return (string) $created->getId();
    }

    /**
     * @return array{id: string, mimeType: string, modifiedTime: ?string, size: ?int}
     */
    private function requireResolved(string $path, Closure $onError): array
    {
        try {
            $resolved = $this->resolve($path);
        } catch (Throwable $e) {
            throw $onError($e);
        }

        if ($resolved === null) {
            throw $onError(new RuntimeException('Path not found.'));
        }

        return $resolved;
    }

    /**
     * @param  resource  $stream  a seekable, sized stream (see writeStream())
     */
    private function upload(?string $existingFileId, string $parentId, string $name, $stream, string $mimeType, int $size): string
    {
        $metadata = new DriveFile(array_filter([
            'name' => $name,
            'parents' => $existingFileId === null ? [$parentId] : null,
        ], static fn ($value) => $value !== null));

        if ($size === 0) {
            // ponytail: the resumable chunked path below can't finalize a
            // zero-length upload cleanly (there is no last chunk to send).
            // An empty file is small enough to just send in one request.
            $optParams = ['data' => '', 'mimeType' => $mimeType, 'uploadType' => 'multipart', 'fields' => 'id'];
            $result = $existingFileId === null
                ? $this->service->files->create($metadata, $optParams)
                : $this->service->files->update($existingFileId, $metadata, $optParams);

            return (string) $result->getId();
        }

        $client = $this->service->getClient();
        $wasDeferred = $client->shouldDefer();
        $client->setDefer(true);

        try {
            $request = $existingFileId === null
                ? $this->service->files->create($metadata, ['fields' => 'id'])
                : $this->service->files->update($existingFileId, $metadata, ['fields' => 'id']);

            $media = new MediaFileUpload($client, $request, $mimeType, '', true, $this->chunkSize);
            $media->setFileSize($size);

            $status = false;

            while ($status === false && ! feof($stream)) {
                $chunk = $this->readChunk($stream, $this->chunkSize);
                $status = $media->nextChunk($chunk);
            }

            /** @var DriveFile $status */
            return (string) $status->getId();
        } finally {
            $client->setDefer($wasDeferred);
        }
    }

    /** @param resource $stream */
    private function readChunk($stream, int $chunkSize): string
    {
        $data = '';

        while (! feof($stream) && strlen($data) < $chunkSize) {
            $data .= fread($stream, $chunkSize - strlen($data));
        }

        return $data;
    }

    private function listChildren(string $folderId, string $path, bool $deep): Generator
    {
        $pageToken = null;

        do {
            $result = $this->service->files->listFiles([
                'q' => sprintf("'%s' in parents and trashed = false", DriveQuery::escape($folderId)),
                'fields' => 'nextPageToken, files(id,name,mimeType,modifiedTime,size)',
                'spaces' => 'drive',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]);

            foreach ($result->getFiles() as $file) {
                $childPath = $path === '' ? (string) $file->getName() : $path.'/'.$file->getName();
                $this->cache->put($childPath, (string) $file->getId());

                if ($file->getMimeType() === self::FOLDER_MIME_TYPE) {
                    yield new DirectoryAttributes($childPath, lastModified: $this->toTimestamp($file->getModifiedTime()));

                    if ($deep) {
                        yield from $this->listChildren((string) $file->getId(), $childPath, true);
                    }
                } else {
                    yield new FileAttributes(
                        $childPath,
                        fileSize: $file->getSize() !== null ? (int) $file->getSize() : null,
                        lastModified: $this->toTimestamp($file->getModifiedTime()),
                        mimeType: $file->getMimeType(),
                    );
                }
            }

            $pageToken = $result->getNextPageToken();
        } while ($pageToken !== null);
    }

    private function toTimestamp(?string $modifiedTime): ?int
    {
        if ($modifiedTime === null) {
            return null;
        }

        $timestamp = strtotime($modifiedTime);

        return $timestamp === false ? null : $timestamp;
    }

    private function dirnameOf(string $path): string
    {
        $dirname = dirname($path);

        return $dirname === '.' ? '' : $dirname;
    }

    private function normalizePath(string $path): string
    {
        return trim($path, '/');
    }
}
