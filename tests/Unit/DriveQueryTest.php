<?php

declare(strict_types=1);

use JeffersonGoncalves\FlysystemGoogleDrive\Support\DriveQuery;

it('escapes a single quote so a filename cannot break out of the query literal', function () {
    expect(DriveQuery::escape("Jefferson's backup.zip"))->toBe("Jefferson\\'s backup.zip");
});

it('escapes a literal backslash before escaping quotes, per Google\'s documented rule', function () {
    expect(DriveQuery::escape('back\\slash'))->toBe('back\\\\slash');
});

it('cannot be used to inject an additional query clause', function () {
    $malicious = "foo' or 'a'='a";

    $escaped = DriveQuery::escape($malicious);

    expect($escaped)->toBe("foo\\' or \\'a\\'=\\'a")
        ->and($escaped)->not->toContain("' or '");
});

it('leaves a plain name untouched', function () {
    expect(DriveQuery::escape('database-backup.zip'))->toBe('database-backup.zip');
});
