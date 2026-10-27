<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Keeps generated sitemaps in the app cache, so a crawler request doesn't query
 * the database and render again while nothing has changed.
 *
 * An entry lives as long as browsers and crawlers may cache the response
 * (`max_age`), so the server is never staler than a shared cache already may be.
 * {@see EventListener\ContentChangeListener} drops all entries as soon as content
 * changes.
 */
final readonly class SitemapCache
{
    public const TAG = 'tomvondracek_sitemap';

    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * The cached value for `$key`, or the result of `$generate`, kept for `$ttl`
     * seconds. Concurrent misses generate once (the cache contracts lock).
     *
     * @template T
     *
     * @param array<array-key, mixed> $key everything the value depends on
     * @param callable(): T $generate
     *
     * @return T
     */
    public function get(array $key, int $ttl, callable $generate): mixed
    {
        if ($ttl < 1) {
            return $generate();
        }

        return $this->cache->get(
            'tomvondracek_sitemap.' . hash('xxh128', serialize($key)),
            static function (ItemInterface $item) use ($ttl, $generate): mixed {
                $item->expiresAfter($ttl);
                $item->tag(self::TAG);

                return $generate();
            }
        );
    }

    public function invalidate(): void
    {
        $this->cache->invalidateTags([self::TAG]);
    }
}
