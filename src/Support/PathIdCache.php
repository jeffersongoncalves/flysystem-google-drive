<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive\Support;

/**
 * A request-scoped, in-memory cache of virtual path => Drive file/folder ID.
 *
 * Google Drive is ID-based, not path-based, so every operation has to resolve
 * a virtual path by walking its segments and asking the API for each child.
 * That's slow for repeated lookups (e.g. a deep listContents()), so resolved
 * IDs are kept here. Correct invalidation on move/delete matters more than
 * caching itself: a stale entry after a rename is exactly the masbug bug
 * class this package exists to avoid ("renamed the wrong folder").
 *
 * ponytail: a flat array is enough for a single request's lifetime. No
 * persistent/cross-request cache — add one (with an invalidation story) only
 * if profiling actually shows path resolution as a bottleneck.
 */
final class PathIdCache
{
    /** @var array<string, string> */
    private array $ids = [];

    public function get(string $path): ?string
    {
        return $this->ids[$path] ?? null;
    }

    public function put(string $path, string $id): void
    {
        $this->ids[$path] = $id;
    }

    public function forget(string $path): void
    {
        unset($this->ids[$path]);
    }

    /**
     * Forget a path and every cached entry nested under it (its descendants),
     * e.g. after deleting a folder.
     */
    public function forgetTree(string $path): void
    {
        $prefix = $path.'/';

        foreach (array_keys($this->ids) as $cachedPath) {
            if ($cachedPath === $path || str_starts_with($cachedPath, $prefix)) {
                unset($this->ids[$cachedPath]);
            }
        }
    }

    /**
     * Move a path (and every descendant nested under it) from $from to $to,
     * keeping each entry's cached ID. Used after move()/rename() so stale
     * entries never linger under the old path.
     */
    public function move(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $prefix = $from.'/';

        foreach ($this->ids as $cachedPath => $id) {
            if ($cachedPath === $from) {
                unset($this->ids[$cachedPath]);
                $this->ids[$to] = $id;

                continue;
            }

            if (str_starts_with($cachedPath, $prefix)) {
                unset($this->ids[$cachedPath]);
                $this->ids[$to.'/'.substr($cachedPath, strlen($prefix))] = $id;
            }
        }
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->ids;
    }
}
