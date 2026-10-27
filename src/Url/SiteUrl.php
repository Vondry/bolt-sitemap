<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

use Bolt\Canonical;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The site's address as `|link(true)` writes it: the host from `general/canonical`
 * when set, otherwise the request's. Every URL in the sitemap is resolved against
 * it, so one file never mixes hosts.
 */
final readonly class SiteUrl
{
    public function __construct(
        private Canonical $canonical,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * `scheme://host[:port]` of the homepage, with a Punycode host; null outside a
     * request. Site-relative paths (`/about`) already contain the base path.
     */
    public function origin(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        // Punycode first: parse_url() mangles non-ASCII hosts.
        $homepage = $this->canonical->get('homepage') ?? $request->getSchemeAndHttpHost();
        $parts = parse_url(UrlNormalizer::normalize($homepage));
        if (! isset($parts['scheme'], $parts['host'])) {
            $parts = parse_url($request->getSchemeAndHttpHost());
        }

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * An absolute, normalized URL for a site-relative path; absolute URLs are only
     * normalized.
     */
    public function absolute(string $pathOrUrl): string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $pathOrUrl) === 1) {
            return UrlNormalizer::normalize($pathOrUrl);
        }

        return UrlNormalizer::normalize(($this->origin() ?? '') . '/' . mb_ltrim($pathOrUrl, '/'));
    }
}
