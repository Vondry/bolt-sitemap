<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tomvondracek\Sitemap\Controller\SitemapController;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\Segment\SegmentRegistry;
use Tomvondracek\Sitemap\Segment\SegmentUrls;
use Tomvondracek\Sitemap\State\StateStore;
use Tomvondracek\Sitemap\Url\Lastmod;
use Tomvondracek\Sitemap\Url\SiteUrl;

/**
 * Generates the sitemap index and its child sitemaps.
 *
 * A child's `<lastmod>` in the index is the later of its newest URL's and the
 * last time a URL was added to or removed from it; a child without
 * either has none. Children without URLs are left out: an empty `<urlset>` is not
 * valid.
 */
final readonly class SitemapGenerator
{
    public function __construct(
        private SegmentRegistry $registry,
        private SegmentUrls $segmentUrls,
        private TemplateRenderer $renderer,
        private StateStore $stateStore,
        private SitemapCache $cache,
        private SiteUrl $siteUrl,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $cached whether a cached copy may be used
     */
    public function index(SitemapConfig $config, bool $cached = true): string
    {
        return $this->cache->get(['index', $this->siteUrl->origin(), $config], $cached ? $config->maxAge : 0, function () use ($config, $cached): string {
            $sitemaps = [];
            foreach ($this->registry->all($config) as $segment) {
                for ($chunk = 1; $chunk <= $segment->chunks; ++$chunk) {
                    $child = $this->child($config, $segment, $chunk, $cached);
                    if ($child['count'] > 0) {
                        $sitemaps[] = [
                            'loc' => $this->childUrl($segment->fileName($chunk)),
                            'lastmod' => $child['lastmod'],
                        ];
                    }
                }
            }

            return $this->renderer->render($config, 'index', [
                'sitemaps' => $sitemaps,
            ] + $this->humanView($config));
        });
    }

    /**
     * @return array{body: string, lastmod: string|null, count: int}
     */
    public function child(SitemapConfig $config, Segment $segment, int $chunk, bool $cached = true): array
    {
        $key = ['child', $segment->fileName($chunk), $this->siteUrl->origin(), $config];

        return $this->cache->get($key, $cached ? $config->maxAge : 0, function () use ($config, $segment, $chunk): array {
            $urls = $this->segmentUrls->urls($config, $segment, $chunk);

            $hasImages = false;
            $hasAlternates = false;
            $locs = [];
            $lastmod = null;
            foreach ($urls as $url) {
                $hasImages = $hasImages || $url->images !== [];
                $hasAlternates = $hasAlternates || $url->alternates !== [];
                $locs[] = $url->loc;
                $lastmod = Lastmod::later($lastmod, $url->lastmod);
            }

            sort($locs);
            $setChangedAt = $urls === [] ? null : $this->stateStore->changedAt(
                'sitemap:' . $segment->fileName($chunk),
                hash('xxh128', implode("\n", $locs)),
                $this->clock->now()
            );
            $lastmod = Lastmod::later($lastmod, $setChangedAt);

            return [
                'body' => $urls === [] ? '' : $this->renderer->render($config, 'urlset', [
                    'urls' => $urls,
                    'hasImages' => $hasImages,
                    'hasAlternates' => $hasAlternates,
                ] + $this->humanView($config)),
                'lastmod' => Lastmod::w3c($lastmod),
                'count' => count($urls),
            ];
        });
    }

    /**
     * The `href` of the stylesheet and the `src` of the script for people opening
     * the sitemap in a browser, or null for none.
     *
     * @return array{stylesheet: string|null, script: string|null}
     */
    private function humanView(SitemapConfig $config): array
    {
        return [
            'stylesheet' => $config->stylesheet === true
                ? $this->urlGenerator->generate(SitemapController::ROUTE_STYLESHEET)
                : ($config->stylesheet === false ? null : $config->stylesheet),
            'script' => $config->script === true
                ? $this->urlGenerator->generate(SitemapController::ROUTE_SCRIPT)
                : ($config->script === false ? null : $config->script),
        ];
    }

    private function childUrl(string $name): string
    {
        return $this->siteUrl->absolute($this->urlGenerator->generate(SitemapController::ROUTE_CHILD, [
            'name' => $name,
        ]));
    }
}
