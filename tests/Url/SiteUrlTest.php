<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Url;

use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class SiteUrlTest extends TestCase
{
    public function testOriginIsTheCanonicalHost(): void
    {
        $siteUrl = Fixtures::siteUrl('https://www.example.com/blog/');

        self::assertSame('https://www.example.com', $siteUrl->origin());
        self::assertSame('https://www.example.com/blog/o-n%C3%A1s', $siteUrl->absolute('/blog/o-nás'));
        self::assertSame('https://cdn.example.net/a%20b.jpg', $siteUrl->absolute('https://cdn.example.net/a b.jpg'), 'absolute URLs keep their host');
    }

    public function testOriginWithPortAndIdnHost(): void
    {
        self::assertSame('https://xn--hky-ela4t.cz:8443', Fixtures::siteUrl('https://háčky.cz:8443/')->origin());
    }

    public function testRequestHostWithoutCanonical(): void
    {
        self::assertSame('http://internal.example.org', Fixtures::siteUrl('/')->origin());
    }

    public function testNoOriginOutsideARequest(): void
    {
        self::assertNull(Fixtures::siteUrl(null)->origin());
    }

    public function testSiteLocales(): void
    {
        $locales = Fixtures::siteLocales('cs');

        self::assertSame('cs', $locales->default());
        self::assertSame(['cs'], $locales->forContentType(null));
        self::assertSame(['en', 'cs'], $locales->forContentType(Fixtures::contentType('pages', [
            'locales' => ['en', ' cs ', 'en', ''],
        ])));
        self::assertSame('cs', $locales->defaultOf(['en', 'cs']));
        self::assertSame('en', $locales->defaultOf(['en', 'de']));
        self::assertSame('en', Fixtures::siteLocales('')->default(), 'a missing %locale% falls back to en');
    }
}
