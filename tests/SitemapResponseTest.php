<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Sitemap\SitemapResponse;

final class SitemapResponseTest extends TestCase
{
    private const XML = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';

    /**
     * Content type, noindex, public caching and a content-hash ETag.
     */
    public function testHeaders(): void
    {
        $response = SitemapResponse::create(self::XML, SitemapResponse::XML, 3600, Request::create('/sitemap.xml'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('"' . hash('xxh128', self::XML . "\n") . '"', $response->getEtag());
        self::assertNull($response->headers->get('Last-Modified'), 'derived from <lastmod>, it would hide removals');
    }

    public function testNotModified(): void
    {
        $first = SitemapResponse::create(self::XML, SitemapResponse::XML, 3600, Request::create('/sitemap.xml'));

        $request = Request::create('/sitemap.xml');
        $request->headers->set('If-None-Match', (string) $first->getEtag());
        $second = SitemapResponse::create(self::XML, SitemapResponse::XML, 3600, $request);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $second->getStatusCode());

        $changed = SitemapResponse::create(str_replace('urlset', 'urlset ', self::XML), SitemapResponse::XML, 3600, $request);
        self::assertSame(Response::HTTP_OK, $changed->getStatusCode(), 'any change, a removal too, changes the ETag');
    }

    public function testPrivateForALoggedInUser(): void
    {
        $response = SitemapResponse::create(self::XML, SitemapResponse::XML, 3600, Request::create('/sitemap.xml'), false);

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testAssetsAreNotXml(): void
    {
        $response = SitemapResponse::create("  body {}\n", SitemapResponse::CSS, 60, Request::create('/sitemap.css'));

        self::assertSame("  body {}\n", $response->getContent());
        self::assertSame('text/css; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('X-Robots-Tag'));
    }

    /**
     * Nothing may precede `<?xml`.
     */
    public function testNothingPrecedesTheXmlDeclaration(): void
    {
        self::assertSame(self::XML . "\n", SitemapResponse::normalizeXml("\xEF\xBB\xBF\n  \n" . self::XML . "\n\n"));
        self::assertSame("<urlset/>\n", SitemapResponse::normalizeXml('<urlset/>'), 'only whitespace before a declaration is removed');
    }
}
