<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Segment;

use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use DateTimeInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tomvondracek\Sitemap\Content\ContentSource;
use Tomvondracek\Sitemap\Content\Homepage;
use Tomvondracek\Sitemap\Content\Images;
use Tomvondracek\Sitemap\Content\RecordRoutes;
use Tomvondracek\Sitemap\Content\SeoRules;
use Tomvondracek\Sitemap\Content\StaticRoutes;
use Tomvondracek\Sitemap\Event\SitemapUrlsEvent;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\State\StateStore;
use Tomvondracek\Sitemap\Url\Lastmod;
use Tomvondracek\Sitemap\Url\RouteUrls;
use Tomvondracek\Sitemap\Url\SiteLocales;
use Tomvondracek\Sitemap\Url\SitemapUrl;

/**
 * The URLs of one file of a segment.
 */
final readonly class SegmentUrls
{
    public function __construct(
        private SegmentRegistry $registry,
        private ContentSource $contentSource,
        private Homepage $homepage,
        private RecordRoutes $recordRoutes,
        private RouteUrls $routeUrls,
        private SiteLocales $siteLocales,
        private StaticRoutes $staticRoutes,
        private Images $images,
        private SeoRules $seoRules,
        private StateStore $stateStore,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function urls(SitemapConfig $config, Segment $segment, int $chunk): array
    {
        $urls = match ($segment->type) {
            Segment::MAIN => $this->main($config),
            Segment::CONTENT_TYPE => $this->contentType($config, (string) $segment->key, $chunk),
            Segment::TAXONOMY => $this->taxonomy((string) $segment->key),
            default => [],
        };

        $event = new SitemapUrlsEvent($segment, $chunk, $urls);
        $this->eventDispatcher->dispatch($event);

        return $event->getUrls();
    }

    /**
     * The homepage, the singletons and the static routes.
     *
     * @return list<SitemapUrl>
     */
    private function main(SitemapConfig $config): array
    {
        $urls = $this->homepageUrls($config);

        foreach ($this->registry->singletons($config) as $contentType) {
            $id = $this->contentSource->findPublishedId($contentType->getSlug(), null);
            foreach ($id === null ? [] : $this->contentSource->byIds([$id]) as $record) {
                $urls = [...$urls, ...$this->recordUrls($config, $record)];
            }
        }

        if ($config->staticRoutes) {
            foreach ($this->staticRoutes->collect($this->siteLocales->default(), $config->excludeStaticRoutes) as $page) {
                $byLocale = [];
                foreach ($page['urls'] as $locale => $url) {
                    if (! $this->seoExcludes($config, $url['url'], $url['route'], $locale)) {
                        $byLocale[$locale] = $url['url'];
                    }
                }

                $urls = [...$urls, ...$this->cluster($config, $byLocale, $config->staticRouteLastmod[$page['name']] ?? null)];
            }
        }

        return $urls;
    }

    /**
     * `/` (and `/<locale>/`): the homepage record's URLs, or, when the setting is
     * empty, the plain homepage. A homepage that shows a listing is left out (its
     * canonical URL is the listing page), and so is one whose setting names no
     * ContentType (Bolt can't render it).
     *
     * @return list<SitemapUrl>
     */
    private function homepageUrls(SitemapConfig $config): array
    {
        $id = $this->registry->homepageRecordId();
        if ($id !== null) {
            $urls = [];
            foreach ($this->contentSource->byIds([$id]) as $record) {
                $urls = [...$urls, ...$this->recordUrls($config, $record)];
            }

            return $urls;
        }

        if ($this->homepage->isConfigured()) {
            return [];
        }

        $url = $this->routeUrls->url('homepage', []);
        if ($url === null || $this->seoExcludes($config, $url['url'], $url['route'], $this->siteLocales->default())) {
            return [];
        }

        return [new SitemapUrl($url['url'])];
    }

    /**
     * The listing page (in the first file) and a slice of the records.
     *
     * @return list<SitemapUrl>
     */
    private function contentType(SitemapConfig $config, string $slug, int $chunk): array
    {
        $contentType = $this->registry->contentType($slug);
        if ($contentType === null) {
            return [];
        }

        $ids = $this->registry->recordIds($config, $contentType);

        $urls = [];
        if ($chunk === 1 && $this->registry->hasListing($config, $contentType)) {
            $urls = $this->listingUrls($config, $contentType);
        }

        $perChunk = $this->registry->recordsPerChunk($config, $contentType);
        foreach ($this->contentSource->byIds(array_slice($ids, ($chunk - 1) * $perChunk, $perChunk)) as $record) {
            $urls = [...$urls, ...$this->recordUrls($config, $record)];
        }

        return $urls;
    }

    /**
     * A listing page's `<lastmod>` is the later of its newest record's and the
     * last time a record was added or removed (spec §1.4).
     *
     * @return list<SitemapUrl>
     */
    private function listingUrls(SitemapConfig $config, ContentType $contentType): array
    {
        $slug = $contentType->getSlug();

        $byLocale = [];
        foreach ($this->siteLocales->forContentType($contentType) as $locale) {
            $route = $this->recordRoutes->forListing($contentType, $locale);
            $url = $this->routeUrls->url($route['route'], $route['params'], $locale);
            if ($url !== null && ! $this->seoExcludes($config, $url['url'], $url['route'], $locale, listing: $slug)) {
                $byLocale[$locale] = $url['url'];
            }
        }

        if ($byLocale === []) {
            return [];
        }

        $ids = $this->registry->publishedIds($slug);
        $setChangedAt = $this->stateStore->changedAt('listing:' . $slug, hash('xxh128', implode(',', $ids)), $this->clock->now());
        $lastmod = Lastmod::later($this->contentSource->lastModified($slug), $setChangedAt);

        return $this->cluster($config, $byLocale, $lastmod, $this->siteLocales->forContentType($contentType));
    }

    /**
     * The taxonomy's pages, in the default locale, without `<lastmod>`: no date
     * of the page's content is known.
     *
     * @return list<SitemapUrl>
     */
    private function taxonomy(string $taxonomy): array
    {
        $urls = [];
        foreach ($this->contentSource->taxonomySlugs($taxonomy) as $slug) {
            $url = $this->routeUrls->url('taxonomy', [
                'taxonomyslug' => $this->registry->taxonomySingularSlug($taxonomy),
                'slug' => $slug,
            ]);
            if ($url !== null) {
                $urls[] = new SitemapUrl($url['url']);
            }
        }

        return $urls;
    }

    /**
     * One URL per locale the record is published in, with its images.
     *
     * @return list<SitemapUrl>
     */
    private function recordUrls(SitemapConfig $config, Content $record): array
    {
        $definition = $record->getDefinition();
        $locales = $this->siteLocales->forContentType($definition);

        $byLocale = [];
        foreach ($locales as $locale) {
            $route = $this->recordRoutes->forRecord($record, $locale);
            $url = $this->routeUrls->url($route['route'], $route['params'], $locale);
            $listing = in_array($route['route'], ['listing', 'listing_locale'], true) ? $record->getContentTypeSlug() : null;

            if ($url !== null && ! $this->seoExcludes($config, $url['url'], $url['route'], $locale, $record, $listing)) {
                $byLocale[$locale] = $url['url'];
            }
        }

        return $this->cluster($config, $byLocale, $record->getModifiedAt(), $locales, $this->images->of($record, $config));
    }

    /**
     * @param array<string, string> $byLocale
     * @param list<string>|null $locales the locales the page exists in; null = those of `$byLocale`
     * @param list<string> $images
     *
     * @return list<SitemapUrl>
     */
    private function cluster(SitemapConfig $config, array $byLocale, ?DateTimeInterface $lastmod, ?array $locales = null, array $images = []): array
    {
        // A route without `{_locale}` gives every locale the same URL: one page.
        $byLocale = array_unique($byLocale);
        if ($byLocale === []) {
            return [];
        }

        $locales = $locales === null || $locales === [] ? array_keys($byLocale) : $locales;
        $defaultLocale = $config->xDefault ? $this->siteLocales->defaultOf($locales) : null;

        return SitemapUrl::cluster($byLocale, $defaultLocale, $lastmod, $images);
    }

    private function seoExcludes(SitemapConfig $config, string $url, string $route, string $locale, ?Content $record = null, ?string $listing = null): bool
    {
        return $config->respectNoindex && $this->seoRules->excludes($url, $route, $locale, $record, $listing);
    }
}
