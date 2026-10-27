<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;

/**
 * The route (and its parameters) of the page that shows a record or a listing:
 * the URL visitors actually see, which is what belongs in a sitemap.
 *
 *  - the homepage record: `homepage_locale` (`/`, `/en/`);
 *  - a singleton with Bolt's default `record_route`: its slugless page on the
 *    listing route with its singular slug (`/kontakt`, not `/kontakt/kontakt-2`),
 *    which Bolt forwards to the record; the canonical URL bolt/core#3735 gives it;
 *  - a singleton with its own `record_route` (e.g. `privacy_locale` with a fixed
 *    path): that route, as is, because its path is already the page's;
 *  - any other record: its `record_route` with its slug in that locale.
 */
final readonly class RecordRoutes
{
    /**
     * Bolt's own record routes; a ContentType without `record_route` gets one of these.
     */
    private const DEFAULT_RECORD_ROUTES = ['record', 'record_locale'];

    public function __construct(
        private Homepage $homepage,
    ) {
    }

    /**
     * @return array{route: string, params: array<string, mixed>}
     */
    public function forRecord(Content $record, string $locale): array
    {
        $definition = $record->getDefinition();

        if ($this->homepage->isHomepage($record)) {
            return [
                'route' => 'homepage_locale',
                'params' => [
                    '_locale' => $locale,
                ],
            ];
        }

        $recordRoute = $definition?->get('record_route');
        $recordRoute = is_string($recordRoute) && $recordRoute !== '' ? $recordRoute : 'record_locale';

        if ($definition?->get('singleton') === true && in_array($recordRoute, self::DEFAULT_RECORD_ROUTES, true)) {
            return [
                'route' => 'listing_locale',
                'params' => [
                    'contentTypeSlug' => $record->getContentTypeSingularSlug(),
                    '_locale' => $locale,
                ],
            ];
        }

        $slug = $record->getSlug($locale);

        return [
            'route' => $recordRoute,
            'params' => [
                'contentTypeSlug' => $record->getContentTypeSingularSlug(),
                'slugOrId' => $slug === null || $slug === '' ? (string) $record->getId() : $slug,
                '_locale' => $locale,
            ],
        ];
    }

    /**
     * The listing page, `/<slug>` or `/<locale>/<slug>`: plural slug, no record slug.
     *
     * @return array{route: string, params: array<string, mixed>}
     */
    public function forListing(ContentType $contentType, string $locale): array
    {
        return [
            'route' => 'listing_locale',
            'params' => [
                'contentTypeSlug' => $contentType->getSlug(),
                '_locale' => $locale,
            ],
        ];
    }
}
