<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Entity\Content;
use DateTimeImmutable;

/**
 * The published content a sitemap lists. Abstracted from Doctrine so the
 * sitemap logic can be tested without a database.
 */
interface ContentSource
{
    /**
     * Ids of the published records of a ContentType, ascending.
     *
     * @return list<int>
     */
    public function publishedIds(string $contentType): array;

    /**
     * The records with these ids, with their fields loaded, in the given order.
     *
     * @param list<int> $ids
     *
     * @return iterable<Content>
     */
    public function byIds(array $ids): iterable;

    /**
     * The latest modification of a published record of a ContentType.
     */
    public function lastModified(string $contentType): ?DateTimeImmutable;

    /**
     * The published record a `homepage: <contenttype>/<id or slug>` setting points
     * to; without an id or slug, the first published record.
     */
    public function findPublishedId(string $contentType, ?string $idOrSlug): ?int;

    /**
     * The distinct slugs of a taxonomy's terms, sorted.
     *
     * @return list<string>
     */
    public function taxonomySlugs(string $type): array;
}
