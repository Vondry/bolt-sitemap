<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use Bolt\Entity\Content;
use Bolt\Repository\ContentRepository;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Content\DoctrineContentSource;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class DoctrineContentSourceTest extends TestCase
{
    /** @var list<string> */
    private array $dql = [];

    /** @var list<object> */
    private array $detached = [];

    public function testPublishedIds(): void
    {
        $source = $this->source(columns: [['3', 5]]);

        self::assertSame([3, 5], $source->publishedIds('pages'));
        self::assertStringContainsString('c.status = :status', $this->dql[0]);
        self::assertStringContainsString('ORDER BY c.id ASC', $this->dql[0]);
    }

    public function testDetachesEachRecordWithItsFieldsOnceTheCallerIsDone(): void
    {
        $first = Fixtures::content(1, Fixtures::contentType('pages'), [
            'cs' => 'a',
        ]);
        $second = Fixtures::content(2, Fixtures::contentType('pages'));

        $source = $this->source(results: [[$first, $second]]);

        $seen = [];
        foreach ($source->byIds([1, 2]) as $content) {
            self::assertNotContains($content, $this->detached, 'still managed while the caller works on it');
            $seen[] = $content;
        }

        self::assertSame([$first, $second], $seen);
        self::assertSame($first, $this->detached[2]);
        self::assertCount(4, $this->detached, 'the slug field, its translation and both records');
        self::assertStringContainsString('LEFT JOIN c.fields f LEFT JOIN f.translations t', $this->dql[0]);
    }

    public function testLoadsInBatches(): void
    {
        $source = $this->source(results: [[], []]);

        iterator_to_array($source->byIds(range(1, DoctrineContentSource::BATCH_SIZE + 1)));

        self::assertCount(2, $this->dql);
    }

    public function testLastModifiedIsUtc(): void
    {
        self::assertSame('2026-10-24T06:30:00+00:00', $this->source(scalars: ['2026-10-24 06:30:00'])->lastModified('pages')?->format(DATE_ATOM));
        self::assertSame('2026-10-24T04:30:00+00:00', $this->source(scalars: [new DateTime('2026-10-24 06:30:00', new DateTimeZone('Europe/Prague'))])->lastModified('pages')?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertNull($this->source(scalars: [null])->lastModified('pages'));
    }

    public function testFindPublishedId(): void
    {
        self::assertSame(3, $this->source(columns: [[3, 5]])->findPublishedId('homepage', null));
        self::assertNull($this->source(columns: [[]])->findPublishedId('homepage', null));
        self::assertSame(5, $this->source(columns: [[3, 5]])->findPublishedId('pages', '5'));
        self::assertNull($this->source(columns: [[3, 5]])->findPublishedId('pages', '4'), 'not published');

        $home = Fixtures::content(5, Fixtures::contentType('pages'));
        self::assertSame(5, $this->source(columns: [[3, 5]], bySlug: $home)->findPublishedId('pages', 'home'));
        self::assertNull($this->source(columns: [[3]], bySlug: $home)->findPublishedId('pages', 'home'), 'not published');
        self::assertNull($this->source(columns: [[3]])->findPublishedId('pages', 'missing'));
    }

    public function testTaxonomySlugs(): void
    {
        self::assertSame(['dvere', 'okna'], $this->source(columns: [['dvere', '', 'okna']])->taxonomySlugs('tags'));
        self::assertStringContainsString('SELECT DISTINCT t.slug', $this->dql[0]);
    }

    /**
     * @param list<list<mixed>> $columns results of getSingleColumnResult(), in order
     * @param list<list<Content>> $results results of getResult(), in order
     * @param list<mixed> $scalars results of getSingleScalarResult(), in order
     */
    private function source(array $columns = [], array $results = [], array $scalars = [], ?Content $bySlug = null): DoctrineContentSource
    {
        $this->dql = [];
        $this->detached = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $query = $this->createMock(Query::class);
        // QueryBuilder::getQuery() chains these; a mock would return a fresh double.
        foreach (['setParameters', 'setFirstResult', 'setMaxResults'] as $setter) {
            $query->method($setter)
                ->willReturnSelf();
        }
        $query->method('getSingleColumnResult')
            ->willReturnOnConsecutiveCalls(...$columns);
        $query->method('getResult')
            ->willReturnOnConsecutiveCalls(...$results);
        $query->method('getSingleScalarResult')
            ->willReturnOnConsecutiveCalls(...$scalars);

        $repository = self::createStub(ContentRepository::class);
        $repository->method('findOneBySlug')
            ->willReturn($bySlug);

        $entityManager->method('createQueryBuilder')
            ->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($entityManager));
        $entityManager->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($query): Query {
                $this->dql[] = $dql;

                return $query;
            });
        $entityManager->method('getRepository')
            ->willReturn($repository);
        $entityManager->method('detach')
            ->willReturnCallback(function (object $entity): void {
                $this->detached[] = $entity;
            });

        return new DoctrineContentSource($entityManager, Fixtures::config());
    }
}
