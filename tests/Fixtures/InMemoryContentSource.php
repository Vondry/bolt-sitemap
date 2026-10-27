<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Fixtures;

use Bolt\Entity\Content;
use DateTimeImmutable;
use Tomvondracek\Sitemap\Content\ContentSource;

/**
 * Published records held in memory.
 */
final class InMemoryContentSource implements ContentSource
{
    /** @var array<int, Content> */
    private array $records = [];

    /** @var list<list<int>> the ids of every byIds() call */
    public array $loads = [];

    /**
     * @param list<Content> $records
     * @param array<string, list<string>> $taxonomies type => slugs
     */
    public function __construct(
        array $records = [],
        public array $taxonomies = [],
    ) {
        foreach ($records as $record) {
            $this->add($record);
        }
    }

    public function add(Content $record): void
    {
        $this->records[(int) $record->getId()] = $record;
        ksort($this->records);
    }

    public function remove(int $id): void
    {
        unset($this->records[$id]);
    }

    public function publishedIds(string $contentType): array
    {
        $ids = [];
        foreach ($this->records as $id => $record) {
            if ($record->getContentTypeSlug() === $contentType) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function byIds(array $ids): iterable
    {
        $this->loads[] = $ids;

        foreach ($ids as $id) {
            if (isset($this->records[$id])) {
                yield $this->records[$id];
            }
        }
    }

    public function lastModified(string $contentType): ?DateTimeImmutable
    {
        $latest = null;
        foreach ($this->records as $record) {
            $modifiedAt = $record->getModifiedAt();
            if ($record->getContentTypeSlug() === $contentType && $modifiedAt !== null && ($latest === null || $modifiedAt > $latest)) {
                $latest = DateTimeImmutable::createFromInterface($modifiedAt);
            }
        }

        return $latest;
    }

    public function findPublishedId(string $contentType, ?string $idOrSlug): ?int
    {
        foreach ($this->records as $id => $record) {
            if ($record->getContentTypeSlug() === $contentType
                && ($idOrSlug === null || $idOrSlug === (string) $id || $idOrSlug === $record->getSlug())) {
                return $id;
            }
        }

        return null;
    }

    public function taxonomySlugs(string $type): array
    {
        return $this->taxonomies[$type] ?? [];
    }
}
