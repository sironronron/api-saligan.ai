<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class CacheLock
{
    /**
     * Atomically acquire a lock; returns true only for the first caller.
     *
     * The lock is held for the dedup window so links discovered across
     * multiple pages in a single crawl run are only dispatched once. The
     * CrawledPage unique constraint and the job's ShouldBeUnique behavior
     * remain the real safety net against duplicate processing.
     */
    public static function acquire(string $key, int $seconds = 86400): bool
    {
        return Cache::lock($key, $seconds)->get();
    }
}
