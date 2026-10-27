<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Segment;

use Bolt\Configuration\Content\ContentType;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Tomvondracek\Sitemap\Tests\Fixtures\Harness;

final class SegmentRegistryTest extends TestCase
{
    public function testSegments(): void
    {
        $clanky = Fixtures::contentType('clanky');
        $blocks = Fixtures::contentType('blocks', [
            'viewless' => true,
            'viewless_listing' => true,
        ]);
        $harness = new Harness([
            Fixtures::contentType('homepage', [
                'singleton' => true,
            ]),
            $clanky,
            $blocks,
            Fixtures::contentType('empty'),
        ], [Fixtures::content(1, $clanky), Fixtures::content(2, $blocks)], [
            'taxonomies' => ['tags'],
        ], taxonomies: [
            'tags' => [
                'slug' => 'tags',
            ],
        ]);

        $segments = $harness->registry->all($harness->config);

        self::assertSame(['main', 'clanky', 'taxonomy-tags'], array_map(static fn (Segment $segment): string => $segment->name, $segments), 'no segment for a ContentType without listing and records, nor for one without published records');
        self::assertSame([Segment::MAIN, Segment::CONTENT_TYPE, Segment::TAXONOMY], array_map(static fn (Segment $segment): string => $segment->type, $segments));
        self::assertSame('clanky-3', $segments[1]->fileName(3));
        self::assertSame('clanky', $segments[1]->fileName(1));
    }

    /**
     * `top-10` is a ContentType, not chunk 10 of `top`.
     */
    public function testExactNamesWinOverChunkNumbers(): void
    {
        $top = Fixtures::contentType('top');
        $top10 = Fixtures::contentType('top-10');
        $harness = new Harness([$top, $top10], [Fixtures::content(1, $top), Fixtures::content(2, $top10)]);

        self::assertSame('top-10', $harness->registry->resolve($harness->config, 'top-10')[0]->name ?? null);
        self::assertSame(1, $harness->registry->resolve($harness->config, 'top-10')[1] ?? null);
        self::assertNull($harness->registry->resolve($harness->config, 'top-11'));
        self::assertNull($harness->registry->resolve($harness->config, 'unknown'));
    }

    public function testMainSegmentMustNotBeAContentTypeSlug(): void
    {
        $harness = new Harness([Fixtures::contentType('pages')], config: [
            'main_segment' => 'pages',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The sitemap `main_segment` "pages" is also the slug of a ContentType');

        $harness->registry->all($harness->config);
    }

    public function testRecordsPerChunk(): void
    {
        $harness = new Harness([]);
        $config = SitemapConfig::fromArray([
            'chunk_size' => 50000,
        ]);

        self::assertSame(16665, $harness->registry->recordsPerChunk($config, Fixtures::contentType('pages', [
            'locales' => ['cs', 'en', 'de'],
        ])), '3 URLs per record and room for the listing: at most 50000 URLs');
        self::assertSame(1, $harness->registry->recordsPerChunk(SitemapConfig::fromArray([
            'chunk_size' => 1,
        ]), Fixtures::contentType('pages')));
    }

    public function testSingletonsWithAPage(): void
    {
        $harness = new Harness([
            Fixtures::contentType('homepage', [
                'singleton' => true,
            ]),
            Fixtures::contentType('kontakt', [
                'singleton' => true,
            ]),
            Fixtures::contentType('settings', [
                'singleton' => true,
                'viewless' => true,
            ]),
            Fixtures::contentType('excluded', [
                'singleton' => true,
            ]),
        ], [], [
            'exclude_contenttypes' => ['excluded'],
        ], [
            'homepage' => 'homepage',
        ]);

        self::assertSame(['kontakt'], array_map(static fn (ContentType $contentType): string => $contentType->getSlug(), $harness->registry->singletons($harness->config)));
    }
}
