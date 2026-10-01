<?php

namespace App\Museum\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Versioned cache namespace for public museum reads. Publishing anything bumps the
 * version, which invalidates every cached public response at once without needing
 * cache tags (works with any cache store).
 */
final class CacheVersion
{
    private const KEY = 'museum:cache-version';

    public static function current(): int
    {
        return (int) Cache::rememberForever(self::KEY, fn () => 1);
    }

    public static function bump(): void
    {
        Cache::forever(self::KEY, self::current() + 1);
    }

    public static function key(string $suffix): string
    {
        return 'museum:v'.self::current().':'.$suffix;
    }

    public static function remember(string $suffix, int $ttl, \Closure $callback): mixed
    {
        return Cache::remember(self::key($suffix), $ttl, $callback);
    }
}
