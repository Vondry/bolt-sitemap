<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Segment;

use Bolt\Configuration\Config;
use Bolt\Configuration\Content\ContentType;
use LogicException;
use Symfony\Contracts\Service\ResetInterface;
use Tomvondracek\Sitemap\Content\ContentSource;
use Tomvondracek\Sitemap\Content\Homepage;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\Url\SiteLocales;

/**
 * The segments of the sitemap index (spec §4.1): the main one (homepage,
 * singletons, static routes), one per ContentType with a listing page or
 * records to list, and one per configured taxonomy.
 *
 * A ContentType with more URLs than `chunk_size` (records × locales) is split
 * into several files.
 */
final class SegmentRegistry implements ResetInterface
{
    public const TAXONOMY_PREFIX = 'taxonomy-';

    /** @var array<string, list<int>> ContentType slug => published record ids */
    private array $publishedIds = [];

    public function __construct(
        private readonly Config $boltConfig,
        private readonly ContentSource $contentSource,
        private readonly Homepage $homepage,
        private readonly SiteLocales $siteLocales,
    ) {
    }

    /**
     * Forgets the record ids between requests of a long-running process.
     */
    public function reset(): void
    {
        $this->publishedIds = [];
    }

    /**
     * @return list<Segment>
     */
    public function all(SitemapConfig $config): array
    {
        $segments = [new Segment($config->mainSegment, Segment::MAIN)];

        foreach ($this->contentTypes() as $contentType) {
            $slug = $contentType->getSlug();
            if ($slug === $config->mainSegment) {
                throw new LogicException(sprintf(
                    'The sitemap `main_segment` "%s" is also the slug of a ContentType, so /sitemap-%1$s.xml would be ambiguous. Choose another name in config/extensions/%s.yaml.',
                    $slug,
                    SitemapConfigLoader::CONFIG_BASENAME
                ));
            }

            $records = count($this->recordIds($config, $contentType));
            if ($records === 0 && ! $this->hasListing($config, $contentType)) {
                continue;
            }

            $chunks = max(1, (int) ceil($records / $this->recordsPerChunk($config, $contentType)));
            $segments[] = new Segment($slug, Segment::CONTENT_TYPE, $slug, $chunks);
        }

        foreach ($config->taxonomies as $taxonomy) {
            if ($this->boltConfig->has('taxonomies/' . $taxonomy)) {
                $segments[] = new Segment(self::TAXONOMY_PREFIX . $taxonomy, Segment::TAXONOMY, $taxonomy);
            }
        }

        return $segments;
    }

    /**
     * The segment and chunk of /sitemap-<name>.xml: `<segment>` is its first file,
     * `<segment>-<n>` its n-th. Exact segment names win, so a ContentType named
     * `top-10` is not mistaken for chunk 10 of `top`.
     *
     * @return array{0: Segment, 1: int}|null
     */
    public function resolve(SitemapConfig $config, string $name): ?array
    {
        $segments = [];
        foreach ($this->all($config) as $segment) {
            $segments[$segment->name] = $segment;
        }

        if (isset($segments[$name])) {
            return [$segments[$name], 1];
        }

        if (preg_match('/^(.+)-([1-9]\d*)$/', $name, $match) === 1 && isset($segments[$match[1]])) {
            $chunk = (int) $match[2];
            if ($chunk >= 2 && $chunk <= $segments[$match[1]]->chunks) {
                return [$segments[$match[1]], $chunk];
            }
        }

        return null;
    }

    /**
     * Ids of the records a ContentType segment lists: published ones, minus the
     * homepage record, which the main segment lists as `/`.
     *
     * @return list<int>
     */
    public function recordIds(SitemapConfig $config, ContentType $contentType): array
    {
        $slug = $contentType->getSlug();
        if (! $config->includesRecords($slug) || $contentType->get('viewless') === true || $contentType->get('singleton') === true) {
            return [];
        }

        $ids = $this->publishedIds($slug);
        $homepageId = $this->homepage->contentType() === $slug ? $this->homepageRecordId() : null;

        return $homepageId === null ? $ids : array_values(array_diff($ids, [$homepageId]));
    }

    /**
     * Ids of the published records of a ContentType, ascending; also those
     * without a page of their own (`viewless`), which a listing page still shows.
     *
     * @return list<int>
     */
    public function publishedIds(string $contentType): array
    {
        return $this->publishedIds[$contentType] ??= $this->contentSource->publishedIds($contentType);
    }

    /**
     * Records per file: `chunk_size` URLs, each record having one per locale, and
     * room for the listing page in the first file.
     *
     * @return int<1, max>
     */
    public function recordsPerChunk(SitemapConfig $config, ContentType $contentType): int
    {
        $locales = count($this->siteLocales->forContentType($contentType));

        return max(1, intdiv($config->chunkSize - $locales, $locales));
    }

    /**
     * Whether the ContentType's listing page is listed: it has one and shows at
     * least one published record. An empty listing is a thin page (spec §3).
     */
    public function hasListing(SitemapConfig $config, ContentType $contentType): bool
    {
        return $config->includesListing($contentType->getSlug())
            && $contentType->get('singleton') !== true
            && $contentType->get('viewless_listing') !== true
            && $contentType->get('hide_listing_from_xml_sitemap') !== true
            && $this->publishedIds($contentType->getSlug()) !== [];
    }

    /**
     * Singleton ContentTypes with a page, listed in the main segment, except the
     * homepage's.
     *
     * @return list<ContentType>
     */
    public function singletons(SitemapConfig $config): array
    {
        $singletons = [];
        foreach ($this->allContentTypes() as $contentType) {
            if ($contentType->get('singleton') === true
                && $contentType->get('viewless') !== true
                && $config->includesRecords($contentType->getSlug())
                && $contentType->getSlug() !== $this->homepage->contentType()) {
                $singletons[] = $contentType;
            }
        }

        return $singletons;
    }

    /**
     * The id of the homepage record, when `/` shows one.
     */
    public function homepageRecordId(): ?int
    {
        $contentType = $this->homepage->contentType();
        if ($contentType === null || $this->homepage->isListing()) {
            return null;
        }

        return $this->contentSource->findPublishedId($contentType, $this->homepage->idOrSlug());
    }

    public function contentType(string $slug): ?ContentType
    {
        return $this->boltConfig->getContentType($slug);
    }

    /**
     * The `{taxonomyslug}` of a taxonomy's pages: its singular slug.
     */
    public function taxonomySingularSlug(string $taxonomy): string
    {
        $singular = $this->boltConfig->get('taxonomies/' . $taxonomy . '/singular_slug');

        return is_string($singular) && $singular !== '' ? $singular : $taxonomy;
    }

    /**
     * ContentTypes that may have a segment of their own: not singletons.
     *
     * @return list<ContentType>
     */
    private function contentTypes(): array
    {
        $contentTypes = [];
        foreach ($this->allContentTypes() as $contentType) {
            if ($contentType->get('singleton') !== true) {
                $contentTypes[] = $contentType;
            }
        }

        return $contentTypes;
    }

    /**
     * @return list<ContentType>
     */
    private function allContentTypes(): array
    {
        $configured = $this->boltConfig->get('contenttypes');

        $contentTypes = [];
        foreach (is_iterable($configured) ? $configured : [] as $contentType) {
            if ($contentType instanceof ContentType) {
                $contentTypes[] = $contentType;
            }
        }

        return $contentTypes;
    }
}
