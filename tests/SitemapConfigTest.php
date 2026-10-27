<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\SitemapConfigLoader;

final class SitemapConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = SitemapConfig::fromArray([]);

        self::assertTrue($config->listings);
        self::assertSame([], $config->excludeContentTypes);
        self::assertSame([], $config->excludeListings);
        self::assertSame([], $config->taxonomies);
        self::assertTrue($config->staticRoutes);
        self::assertSame([], $config->excludeStaticRoutes);
        self::assertSame([], $config->staticRouteLastmod);
        self::assertSame('main', $config->mainSegment);
        self::assertSame(10000, $config->chunkSize);
        self::assertSame(3600, $config->maxAge);
        self::assertTrue($config->xDefault);
        self::assertTrue($config->images);
        self::assertSame([], $config->imageFields);
        self::assertTrue($config->respectNoindex);
        self::assertFalse($config->stylesheet);
        self::assertFalse($config->script);
        self::assertSame(SitemapConfig::DEFAULT_TEMPLATES, $config->templates);
    }

    /**
     * The shipped config.yaml documents the defaults; it must not change them.
     */
    public function testShippedConfigFileHoldsTheDefaults(): void
    {
        $shipped = Yaml::parseFile(SitemapConfigLoader::defaultConfigFile());
        self::assertIsArray($shipped);

        self::assertEquals(SitemapConfig::fromArray([]), SitemapConfig::fromArray($shipped));
    }

    public function testEveryOption(): void
    {
        $config = SitemapConfig::fromArray([
            'listings' => false,
            'exclude_contenttypes' => ['blocks', ' blocks ', ''],
            'exclude_listings' => ['pages'],
            'taxonomies' => ['tags'],
            'static_routes' => false,
            'exclude_static_routes' => ['internal'],
            'static_route_lastmod' => [
                'about' => Yaml::parse('2026-10-01'),
                'contact' => '2026-10-02T08:00:00+02:00',
            ],
            'main_segment' => 'pages-2',
            'chunk_size' => '500',
            'max_age' => 0,
            'hreflang' => [
                'x_default' => false,
            ],
            'images' => [
                'enabled' => false,
                'fields' => [
                    'products' => ['image', 'gallery'],
                ],
            ],
            'seo' => [
                'respect_noindex' => false,
            ],
            'human_view' => [
                'stylesheet' => true,
                'script' => ' /theme/site/sitemap.js ',
            ],
            'templates' => [
                'urlset' => 'partials/urlset.xml.twig',
            ],
        ]);

        self::assertFalse($config->listings);
        self::assertSame(['blocks'], $config->excludeContentTypes);
        self::assertFalse($config->includesRecords('blocks'));
        self::assertTrue($config->includesRecords('pages'));
        self::assertFalse($config->includesListing('entries'), 'listings are off altogether');
        self::assertSame(['tags'], $config->taxonomies);
        self::assertFalse($config->staticRoutes);
        self::assertSame(['internal'], $config->excludeStaticRoutes);
        self::assertSame('2026-10-01T00:00:00+00:00', $config->staticRouteLastmod['about']->format(DATE_ATOM));
        self::assertSame('2026-10-02T06:00:00+00:00', $config->staticRouteLastmod['contact']->format(DATE_ATOM));
        self::assertSame('pages-2', $config->mainSegment);
        self::assertSame(500, $config->chunkSize);
        self::assertSame(0, $config->maxAge);
        self::assertFalse($config->xDefault);
        self::assertFalse($config->images);
        self::assertSame(['image', 'gallery'], $config->imageFieldsFor('products'));
        self::assertNull($config->imageFieldsFor('pages'));
        self::assertFalse($config->respectNoindex);
        self::assertTrue($config->stylesheet);
        self::assertSame('/theme/site/sitemap.js', $config->script);
        self::assertSame('partials/urlset.xml.twig', $config->templates['urlset']);
        self::assertSame('sitemap-index.xml.twig', $config->templates['index']);
    }

    public function testExcludedListing(): void
    {
        $config = SitemapConfig::fromArray([
            'exclude_listings' => ['pages'],
        ]);

        self::assertFalse($config->includesListing('pages'));
        self::assertTrue($config->includesListing('entries'));
    }

    public function testDateTimeValue(): void
    {
        $config = SitemapConfig::fromArray([
            'static_route_lastmod' => [
                'about' => new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Europe/Prague')),
            ],
        ]);

        self::assertSame('2026-10-01T10:00:00+00:00', $config->staticRouteLastmod['about']->format(DATE_ATOM));
    }

    public function testUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sitemap config key(s) "show_listings"');
        $this->expectExceptionMessage('config/extensions/tomvondracek-sitemap.yaml');

        SitemapConfig::fromArray([
            'show_listings' => true,
        ]);
    }

    public function testUnknownNestedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sitemap config key(s) "human_view.xsl"; the known ones are "human_view.stylesheet", "human_view.script"');

        SitemapConfig::fromArray([
            'human_view' => [
                'xsl' => true,
            ],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'bool' => [[
            'listings' => 'yes',
        ], '`listings` must be true or false, \'yes\' given'];
        yield 'list' => [[
            'exclude_contenttypes' => 'pages',
        ], '`exclude_contenttypes` must be a list of names'];
        yield 'list of non-strings' => [[
            'taxonomies' => [1],
        ], '`taxonomies` must be a list of names'];
        yield 'map' => [[
            'hreflang' => ['x_default'],
        ], '`hreflang` must be a map'];
        yield 'nested bool' => [[
            'seo' => [
                'respect_noindex' => 1,
            ],
        ], '`seo.respect_noindex` must be true or false'];
        yield 'chunk size too big' => [[
            'chunk_size' => 50001,
        ], '`chunk_size` must be a whole number from 1 to 50000'];
        yield 'chunk size zero' => [[
            'chunk_size' => 0,
        ], '`chunk_size` must be a whole number from 1 to 50000'];
        yield 'negative max age' => [[
            'max_age' => -1,
        ], '`max_age` must be a whole number 0 or more'];
        yield 'max age float' => [[
            'max_age' => 1.5,
        ], '`max_age` must be a whole number 0 or more, 1.5 given'];
        yield 'segment name' => [[
            'main_segment' => 'my pages',
        ], '`main_segment` must be letters, digits, "_" and "-"'];
        yield 'segment name type' => [[
            'main_segment' => ['main'],
        ], '`main_segment` must be a string, array given'];
        yield 'asset' => [[
            'human_view' => [
                'script' => 1,
            ],
        ], '`human_view.script` must be a string'];
        yield 'empty asset' => [[
            'human_view' => [
                'stylesheet' => ' ',
            ],
        ], '`human_view.stylesheet` must be true, false or a URL'];
        yield 'date' => [[
            'static_route_lastmod' => [
                'about' => 'someday',
            ],
        ], '`static_route_lastmod.about` must be a date'];
        yield 'date map' => [[
            'static_route_lastmod' => ['about'],
        ], '`static_route_lastmod` must be a map'];
        yield 'image fields' => [[
            'images' => [
                'fields' => [
                    'products' => 'image',
                ],
            ],
        ], '`images.fields.products` must be a list of names'];
        yield 'template' => [[
            'templates' => [
                'index' => false,
            ],
        ], '`templates.index` must be a string'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValue(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        SitemapConfig::fromArray($config);
    }
}
