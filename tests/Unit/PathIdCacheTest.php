<?php

declare(strict_types=1);

use JeffersonGoncalves\FlysystemGoogleDrive\Support\PathIdCache;

it('returns null for an unknown path', function () {
    $cache = new PathIdCache;

    expect($cache->get('backups/db.zip'))->toBeNull();
});

it('stores and retrieves an id', function () {
    $cache = new PathIdCache;
    $cache->put('backups/db.zip', 'file-1');

    expect($cache->get('backups/db.zip'))->toBe('file-1');
});

it('forgets a single path without touching siblings', function () {
    $cache = new PathIdCache;
    $cache->put('backups/db.zip', 'file-1');
    $cache->put('backups/env.zip', 'file-2');

    $cache->forget('backups/db.zip');

    expect($cache->get('backups/db.zip'))->toBeNull()
        ->and($cache->get('backups/env.zip'))->toBe('file-2');
});

it('forgets a folder and every descendant on forgetTree, e.g. after deleting it', function () {
    $cache = new PathIdCache;
    $cache->put('backups', 'folder-1');
    $cache->put('backups/db.zip', 'file-1');
    $cache->put('backups/nested/env.zip', 'file-2');
    $cache->put('other/file.txt', 'file-3');

    $cache->forgetTree('backups');

    expect($cache->get('backups'))->toBeNull()
        ->and($cache->get('backups/db.zip'))->toBeNull()
        ->and($cache->get('backups/nested/env.zip'))->toBeNull()
        ->and($cache->get('other/file.txt'))->toBe('file-3');
});

it('moves a single file entry to its new path, keeping the same id', function () {
    $cache = new PathIdCache;
    $cache->put('old/db.zip', 'file-1');

    $cache->move('old/db.zip', 'new/db.zip');

    expect($cache->get('old/db.zip'))->toBeNull()
        ->and($cache->get('new/db.zip'))->toBe('file-1');
});

it('moves a folder and every descendant, not just the top-level entry', function () {
    // Regression: masbug renamed a subfolder by ID but its path resolution
    // didn't account for the item's real parent chain, and something at the
    // Drive root got renamed instead. A correct move must re-key every
    // cached descendant under the new path, not just the folder itself.
    $cache = new PathIdCache;
    $cache->put('backups', 'folder-1');
    $cache->put('backups/db.zip', 'file-1');
    $cache->put('backups/nested/env.zip', 'file-2');
    $cache->put('unrelated/file.txt', 'file-3');

    $cache->move('backups', 'archive/backups');

    expect($cache->get('backups'))->toBeNull()
        ->and($cache->get('backups/db.zip'))->toBeNull()
        ->and($cache->get('backups/nested/env.zip'))->toBeNull()
        ->and($cache->get('archive/backups'))->toBe('folder-1')
        ->and($cache->get('archive/backups/db.zip'))->toBe('file-1')
        ->and($cache->get('archive/backups/nested/env.zip'))->toBe('file-2')
        ->and($cache->get('unrelated/file.txt'))->toBe('file-3');
});

it('does nothing when moving a path onto itself', function () {
    $cache = new PathIdCache;
    $cache->put('backups/db.zip', 'file-1');

    $cache->move('backups/db.zip', 'backups/db.zip');

    expect($cache->get('backups/db.zip'))->toBe('file-1');
});
