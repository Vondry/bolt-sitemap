<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Url;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Url\Lastmod;
use Tomvondracek\Sitemap\Url\SitemapUrl;

final class SitemapUrlTest extends TestCase
{
    public function testOneLanguageHasNoAlternates(): void
    {
        $urls = SitemapUrl::cluster([
            'cs' => 'https://example.com/cenik',
        ], 'cs');

        self::assertCount(1, $urls);
        self::assertSame('https://example.com/cenik', $urls[0]->loc);
        self::assertSame([], $urls[0]->alternates);
        self::assertNull($urls[0]->lastmodW3c());
    }

    /**
     * Spec §4.2: each language version has its own <url> with the full reciprocal
     * set, including a self-reference.
     */
    public function testEveryLanguageGetsTheFullReciprocalSet(): void
    {
        $urls = SitemapUrl::cluster([
            'cs' => 'https://example.com/sluzby',
            'en' => 'https://example.com/en/services',
            'pt_BR' => 'https://example.com/pt_BR/servicos',
        ], 'cs', new DateTimeImmutable('2026-10-21 20:57:00', new DateTimeZone('Europe/Prague')), ['https://example.com/files/a.jpg']);

        self::assertSame(['https://example.com/sluzby', 'https://example.com/en/services', 'https://example.com/pt_BR/servicos'], array_map(static fn (SitemapUrl $url): string => $url->loc, $urls));

        $expected = [
            'cs' => 'https://example.com/sluzby',
            'en' => 'https://example.com/en/services',
            'pt-BR' => 'https://example.com/pt_BR/servicos',
            'x-default' => 'https://example.com/sluzby',
        ];
        foreach ($urls as $url) {
            self::assertSame($expected, $url->alternates);
            self::assertSame(['https://example.com/files/a.jpg'], $url->images);
            self::assertSame('2026-10-21T18:57:00+00:00', $url->lastmodW3c(), 'UTC, no hardcoded offset');
        }
    }

    public function testNoXDefaultWithoutDefaultLocale(): void
    {
        $urls = SitemapUrl::cluster([
            'cs' => 'https://example.com/',
            'en' => 'https://example.com/en/',
        ], null);

        self::assertArrayNotHasKey('x-default', $urls[0]->alternates);
    }

    public function testNoXDefaultWhenTheDefaultLocaleIsMissing(): void
    {
        $urls = SitemapUrl::cluster([
            'en' => 'https://example.com/en/',
            'de' => 'https://example.com/de/',
        ], 'cs');

        self::assertSame(['en', 'de'], array_keys($urls[0]->alternates));
    }

    public function testLater(): void
    {
        $early = new DateTimeImmutable('2026-01-01');
        $late = new DateTimeImmutable('2026-02-01');

        self::assertEquals($late, Lastmod::later($early, $late));
        self::assertEquals($late, Lastmod::later($late, $early));
        self::assertEquals($early, Lastmod::later($early, null));
        self::assertEquals($early, Lastmod::later(null, $early));
        self::assertNull(Lastmod::later(null, null));
        self::assertNull(Lastmod::w3c(null));
    }
}
