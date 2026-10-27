<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Configuration\Config;
use Bolt\Entity\Content;
use Bolt\Entity\Taxonomy;
use Bolt\Enum\Statuses;
use Bolt\Repository\ContentRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineContentSource implements ContentSource
{
    /**
     * Records loaded per query.
     */
    public const BATCH_SIZE = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Config $boltConfig,
    ) {
    }

    public function publishedIds(string $contentType): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('c.id')
            ->from(Content::class, 'c')
            ->where('c.contentType = :contentType')
            ->andWhere('c.status = :status')
            ->setParameter('contentType', $contentType)
            ->setParameter('status', Statuses::PUBLISHED)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    /**
     * Loads the records in batches, with their fields and field translations
     * fetch-joined: a plain `findBy()` loads those per record (N+1 queries).
     *
     * Each record is detached once the caller is done with it, so a large sitemap
     * does not keep every record in the identity map.
     */
    public function byIds(array $ids): iterable
    {
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            /** @var list<Content> $contents */
            $contents = $this->entityManager->createQueryBuilder()
                ->select('c', 'f', 't')
                ->from(Content::class, 'c')
                ->leftJoin('c.fields', 'f')
                ->leftJoin('f.translations', 't')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $batch)
                ->orderBy('c.id', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($contents as $content) {
                yield $content;
                $this->detach($content);
            }
        }
    }

    public function lastModified(string $contentType): ?DateTimeImmutable
    {
        $latest = $this->entityManager->createQueryBuilder()
            ->select('MAX(c.modifiedAt)')
            ->from(Content::class, 'c')
            ->where('c.contentType = :contentType')
            ->andWhere('c.status = :status')
            ->setParameter('contentType', $contentType)
            ->setParameter('status', Statuses::PUBLISHED)
            ->getQuery()
            ->getSingleScalarResult();

        if ($latest instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($latest);
        }

        // MAX() returns the raw database value, a string; Bolt stores dates in UTC
        // (Content::setModifiedAt() converts them).
        return is_string($latest) && $latest !== '' ? new DateTimeImmutable($latest, new DateTimeZone('UTC')) : null;
    }

    public function findPublishedId(string $contentType, ?string $idOrSlug): ?int
    {
        $ids = $this->publishedIds($contentType);

        if ($idOrSlug === null) {
            return $ids[0] ?? null;
        }

        if (ctype_digit($idOrSlug)) {
            return in_array((int) $idOrSlug, $ids, true) ? (int) $idOrSlug : null;
        }

        /** @var ContentRepository $repository */
        $repository = $this->entityManager->getRepository(Content::class);
        $record = $repository->findOneBySlug($idOrSlug, $this->boltConfig->getContentType($contentType));
        $id = $record?->getId();

        return $id !== null && in_array($id, $ids, true) ? $id : null;
    }

    public function taxonomySlugs(string $type): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT t.slug')
            ->from(Taxonomy::class, 't')
            ->where('t.type = :type')
            ->setParameter('type', $type)
            ->orderBy('t.slug', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($slugs, static fn (mixed $slug): bool => is_string($slug) && $slug !== ''));
    }

    /**
     * Not `clear()`: that would also detach the logged-in user (loaded as an
     * author), which the security token and voters keep using.
     */
    private function detach(Content $content): void
    {
        foreach ($content->getRawFields() as $field) {
            foreach ($field->getTranslations() as $translation) {
                $this->entityManager->detach($translation);
            }
            $this->entityManager->detach($field);
        }

        $this->entityManager->detach($content);
    }
}
