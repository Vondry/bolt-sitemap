<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\Url\SitemapUrl;

/**
 * Dispatched for every child sitemap before it is rendered, so a project can add,
 * remove or change URLs without overriding a template:
 *
 *     #[AsEventListener]
 *     public function __invoke(SitemapUrlsEvent $event): void
 *     {
 *         if ($event->segment->type === Segment::MAIN) {
 *             $event->addUrl(new SitemapUrl('https://example.com/pricing'));
 *         }
 *     }
 *
 * URLs must be absolute and on the site's host (spec §1.3). The sitemap is
 * cached until content changes, so listeners must not depend on the request.
 */
final class SitemapUrlsEvent extends Event
{
    /**
     * @param list<SitemapUrl> $urls
     */
    public function __construct(
        public readonly Segment $segment,
        public readonly int $chunk,
        private array $urls,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    /**
     * @param list<SitemapUrl> $urls
     */
    public function setUrls(array $urls): void
    {
        $this->urls = array_values($urls);
    }

    public function addUrl(SitemapUrl $url): void
    {
        $this->urls[] = $url;
    }
}
