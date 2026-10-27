<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * Builds the response for a sitemap or one of its human-view assets:
 * `application/xml; charset=UTF-8`, `X-Robots-Tag: noindex` (keeps the file
 * itself out of search results) and a content-hash ETag, which changes on
 * additions, edits and removals alike. There is deliberately no `Last-Modified`:
 * derived from the newest `<lastmod>` it would hide removals.
 */
final class SitemapResponse
{
    public const XML = 'application/xml; charset=UTF-8';
    public const CSS = 'text/css; charset=UTF-8';
    public const JAVASCRIPT = 'text/javascript; charset=UTF-8';

    /**
     * @param bool $shared whether the body is the same for every visitor, so shared
     *                     caches may store it; false for a logged-in user
     */
    public static function create(string $body, string $contentType, int $maxAge, Request $request, bool $shared = true): Response
    {
        if ($contentType === self::XML) {
            $body = self::normalizeXml($body);
        }

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($contentType === self::XML) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        $response->setMaxAge($maxAge);
        $response->setEtag(hash('xxh128', $body));

        if ($shared) {
            // Symfony turns every response of a request that used the session into
            // `private, max-age=0`, and Bolt's main firewall uses it on every
            // frontend request, even without a session cookie. Opting out is the
            // documented way to cache such a response ("HTTP Caching and User
            // Sessions"); SitemapResponseSubscriber makes it private again should
            // a cookie be set after all.
            $response->setPublic();
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        } else {
            $response->setPrivate();
        }

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Nothing may precede `<?xml`: browsers reject the file. A BOM or a
     * blank line from an overridden template is removed, as is any trailing
     * whitespace, which is replaced by one newline.
     *
     * @internal
     */
    public static function normalizeXml(string $body): string
    {
        $body = preg_replace('/^(?:\xEF\xBB\xBF|\s)+(?=<\?xml)/', '', $body) ?? $body;

        return mb_rtrim($body) . "\n";
    }
}
