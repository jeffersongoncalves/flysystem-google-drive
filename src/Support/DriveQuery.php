<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive\Support;

/**
 * Escapes values interpolated into a Google Drive API `q` search string.
 *
 * Per Google's documented escaping rule, backslashes and single quotes inside
 * a query string literal must be backslash-escaped. Any filename or path
 * segment that ends up inside a `q` value MUST go through escape() first, or
 * a filename containing a `'` (e.g. `Jefferson's backup.zip`) can break out of
 * the string literal and inject arbitrary query clauses.
 */
final class DriveQuery
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
