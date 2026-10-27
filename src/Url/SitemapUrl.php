<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One `<url>` of a sitemap: an absolute, normalized location with its optional
 * last modification, `hreflang` alternates and images.
 */
final readonly class SitemapUrl
{
    /**
     * @param array<string, string> $alternates hreflang code (`cs`, `en-GB`, `x-default`) => absolute URL
     * @param list<string> $images absolute image URLs
     */
    public function __construct(
        public string $loc,
        public ?DateTimeImmutable $lastmod = null,
        public array $alternates = [],
        public array $images = [],
    ) {
    }

    /**
     * One URL per locale, each with the complete, reciprocal set of alternates
     * including itself. A page in one language gets no alternates.
     *
     * @param array<string, string> $urlsByLocale locale => absolute URL
     * @param string|null $defaultLocale locale of the `x-default` alternate; null = none
     * @param list<string> $images
     *
     * @return list<self>
     */
    public static function cluster(array $urlsByLocale, ?string $defaultLocale, ?DateTimeInterface $lastmod = null, array $images = []): array
    {
        $lastmod = $lastmod === null ? null : DateTimeImmutable::createFromInterface($lastmod);

        $alternates = [];
        if (count($urlsByLocale) > 1) {
            foreach ($urlsByLocale as $locale => $url) {
                $alternates[self::hreflang($locale)] = $url;
            }

            if ($defaultLocale !== null && isset($urlsByLocale[$defaultLocale])) {
                $alternates['x-default'] = $urlsByLocale[$defaultLocale];
            }
        }

        $urls = [];
        foreach ($urlsByLocale as $url) {
            $urls[] = new self($url, $lastmod, $alternates, $images);
        }

        return $urls;
    }

    /**
     * W3C Datetime in UTC (`2026-10-22T06:51:00+00:00`), or null when unknown: a
     * timezone offset is never hardcoded.
     */
    public function lastmodW3c(): ?string
    {
        return Lastmod::w3c($this->lastmod);
    }

    /**
     * Bolt locales use `_` (`pt_BR`), hreflang uses `-` (`pt-BR`).
     */
    private static function hreflang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
