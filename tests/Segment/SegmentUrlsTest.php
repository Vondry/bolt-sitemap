<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Segment;

use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Bolt\Entity\Field;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tomvondracek\Sitemap\Event\SitemapUrlsEvent;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Tomvondracek\Sitemap\Tests\Fixtures\Harness;
use Tomvondracek\Sitemap\Url\SitemapUrl;

/**
 * The URLs each child sitemap lists, for the setups of real projects.
 */
final class SegmentUrlsTest extends TestCase
{
    /**
     * bolt/sitemap-extension#5: a singleton is listed once, at its slugless page
     * (`/kontakt`, not `/kontakt/kontakt-2` plus a listing entry), in every
     * locale, with alternates that are slugless too.
     */
    public function testSingletonIsListedOnceAtItsSluglessUrl(): void
    {
        $kontakt = Fixtures::contentType('kontakt', [
            'singleton' => true,
            'singular_slug' => 'kontakt',
            'locales' => ['cs', 'en', 'de'],
        ]);
        $harness = $this->harness([$kontakt], [Fixtures::content(7, $kontakt, [
            'cs' => 'kontakt-2',
            'en' => 'contact',
        ], '2026-10-02 08:00:00')], general: [
            'homepage' => 'missing',
        ]);

        self::assertSame(['https://example.com/kontakt', 'https://example.com/en/kontakt', 'https://example.com/de/kontakt'], $harness->locs('main'));
        self::assertSame([
            'cs' => 'https://example.com/kontakt',
            'en' => 'https://example.com/en/kontakt',
            'de' => 'https://example.com/de/kontakt',
            'x-default' => 'https://example.com/kontakt',
        ], $harness->url('main', 'https://example.com/en/kontakt')?->alternates);
        self::assertSame('2026-10-02T08:00:00+00:00', $harness->url('main', 'https://example.com/kontakt')?->lastmodW3c());
        self::assertNull($harness->registry->resolve($harness->config, 'kontakt'), 'no segment of its own');
    }

    /**
     * eurodeck: `contact_info` has slug `contact-info` and singular slug
     * `kontakt`; the site's page (and its canonical, bolt/core#3735) is /kontakt.
     */
    public function testSingletonUsesItsSingularSlug(): void
    {
        $contactInfo = Fixtures::contentType('contact-info', [
            'singleton' => true,
            'singular_slug' => 'kontakt',
        ]);
        $harness = $this->harness([$contactInfo], [Fixtures::content(7, $contactInfo)], general: [
            'homepage' => 'missing',
        ]);

        self::assertSame(['https://example.com/kontakt', 'https://example.com/en/kontakt'], $harness->locs('main'));
    }

    /**
     * akvelmez: `privacy` is a singleton with `record_route: privacy_locale`, a
     * fixed path that happens to end with the record's slug. It must be listed
     * as is, not stripped to `/`.
     */
    public function testSingletonWithItsOwnRecordRouteKeepsIt(): void
    {
        $privacy = Fixtures::contentType('privacy', [
            'singleton' => true,
            'singular_slug' => 'privacy',
            'record_route' => 'privacy_locale',
        ]);
        $routes = Fixtures::routes();
        $routes->add('privacy', new Route('/zasady-ochrany-osobnich-udaju', [
            'contentTypeSlug' => 'privacy',
            'slugOrId' => 'zasady-ochrany-osobnich-udaju',
        ]));
        $routes->add('privacy_locale', new Route('/{_locale}/zasady-ochrany-osobnich-udaju', [
            'contentTypeSlug' => 'privacy',
            'slugOrId' => 'zasady-ochrany-osobnich-udaju',
        ], [
            '_locale' => 'cs|en',
        ]));

        $harness = $this->harness([$privacy], [Fixtures::content(3, $privacy, [
            'cs' => 'zasady-ochrany-osobnich-udaju',
        ])], general: [
            'homepage' => 'missing',
        ], routes: $routes);

        self::assertSame([
            'https://example.com/zasady-ochrany-osobnich-udaju',
            'https://example.com/en/zasady-ochrany-osobnich-udaju',
        ], $harness->locs('main'));
    }

    public function testHomepageRecordIsTheRootInEveryLocale(): void
    {
        $homepage = Fixtures::contentType('homepage', [
            'singleton' => true,
            'singular_slug' => 'homepage',
            'viewless' => true,
        ]);
        $harness = $this->harness([$homepage], [Fixtures::content(1, $homepage, [
            'cs' => 'homepage',
        ], '2026-10-01 10:00:00')], general: [
            'homepage' => 'homepage',
        ]);

        self::assertSame(['https://example.com/', 'https://example.com/en/'], $harness->locs('main'), 'even when viewless');
    }

    /**
     * `homepage: pages/home`: that record is `/`, not also `/page/home`.
     */
    public function testHomepageRecordOfAnotherContentTypeIsListedOnceAsTheRoot(): void
    {
        $pages = Fixtures::contentType('pages', [
            'locales' => [],
        ]);
        $harness = $this->harness([$pages], [
            Fixtures::content(1, $pages, [
                'cs' => 'home',
            ]),
            Fixtures::content(2, $pages, [
                'cs' => 'about',
            ]),
        ], general: [
            'homepage' => 'pages/home',
        ]);

        self::assertSame(['https://example.com/'], $harness->locs('main'));
        self::assertSame(['https://example.com/pages', 'https://example.com/page/about'], $harness->locs('pages'));
    }

    public function testRootWithoutHomepageSetting(): void
    {
        $harness = $this->harness([], general: [
            'homepage' => null,
        ]);

        self::assertSame(['https://example.com/'], $harness->locs('main'));
        self::assertNull($harness->urls('main')[0]->lastmod, 'no honest date for a hand-assembled homepage');
    }

    /**
     * `homepage: entries` forwards `/` to the listing, whose canonical URL is
     * /entries: the root is not listed on its own.
     */
    public function testHomepageListingIsNotListedTwice(): void
    {
        $entries = Fixtures::contentType('entries');
        $harness = $this->harness([$entries], [Fixtures::content(1, $entries, [
            'cs' => 'a',
        ])], general: [
            'homepage' => 'entries',
        ]);

        self::assertSame([], $harness->locs('main'));
        self::assertContains('https://example.com/entries', $harness->locs('entries'));
    }

    public function testRecordsInEveryLocaleWithReciprocalAlternates(): void
    {
        $clanky = Fixtures::contentType('clanky', [
            'singular_slug' => 'clanek',
        ]);
        $harness = $this->harness([$clanky], [Fixtures::content(5, $clanky, [
            'cs' => 'okna-a-dvere',
            'en' => 'windows-and-doors',
        ], '2026-10-03 06:30:00')]);

        self::assertSame([
            'https://example.com/clanky',
            'https://example.com/en/clanky',
            'https://example.com/clanek/okna-a-dvere',
            'https://example.com/en/clanek/windows-and-doors',
        ], $harness->locs('clanky'));

        $record = $harness->url('clanky', 'https://example.com/en/clanek/windows-and-doors');
        self::assertSame([
            'cs' => 'https://example.com/clanek/okna-a-dvere',
            'en' => 'https://example.com/en/clanek/windows-and-doors',
            'x-default' => 'https://example.com/clanek/okna-a-dvere',
        ], $record?->alternates);
    }

    /**
     * fmautoservis: listing alternates use the listing route with the plural slug
     * (`/en/clanky`), never a record's link with its slug cut off (`/en/clanek`).
     */
    public function testListingAlternatesUseThePluralSlug(): void
    {
        $clanky = Fixtures::contentType('clanky', [
            'singular_slug' => 'clanek',
        ]);
        $harness = $this->harness([$clanky], [Fixtures::content(5, $clanky, [
            'cs' => 'a',
        ])]);

        self::assertSame([
            'cs' => 'https://example.com/clanky',
            'en' => 'https://example.com/en/clanky',
            'x-default' => 'https://example.com/clanky',
        ], $harness->url('clanky', 'https://example.com/clanky')?->alternates);
    }

    public function testRecordWithoutSlugUsesItsId(): void
    {
        $items = Fixtures::contentType('items', [
            'locales' => [],
        ]);
        $harness = $this->harness([$items], [Fixtures::content(9, $items)], [
            'listings' => false,
        ]);

        self::assertSame(['https://example.com/item/9'], $harness->locs('items'));
        self::assertSame([], $harness->urls('items')[0]->alternates, 'one language: no hreflang');
    }

    /**
     * minibagry: `stroje` uses a catch-all `record_route: strojebinding` without
     * `{_locale}`; every locale would give the same URL, which is listed once.
     */
    public function testCustomRecordRouteWithoutLocale(): void
    {
        $stroje = Fixtures::contentType('stroje', [
            'singular_slug' => 'stroj',
            'record_route' => 'strojebinding',
        ]);
        $routes = Fixtures::routes();
        $routes->add('strojebinding', new Route('/{slugOrId}'));

        $harness = $this->harness([$stroje], [Fixtures::content(2, $stroje, [
            'cs' => 'minibagr-jcb',
        ])], [
            'listings' => false,
        ], routes: $routes);

        self::assertSame(['https://example.com/minibagr-jcb'], $harness->locs('stroje'));
    }

    /**
     * minibagry `videa` (viewless records, visible listing) and akvelmez `lawyers`
     * (records, `viewless_listing`).
     */
    public function testViewlessRecordsAndViewlessListings(): void
    {
        $videa = Fixtures::contentType('videa', [
            'viewless' => true,
            'viewless_listing' => false,
            'locales' => [],
        ]);
        $lawyers = Fixtures::contentType('lawyers', [
            'singular_slug' => 'o-nas',
            'viewless_listing' => true,
            'locales' => [],
        ]);
        $harness = $this->harness([$videa, $lawyers], [
            Fixtures::content(1, $videa, [
                'cs' => 'video',
            ]),
            Fixtures::content(2, $lawyers, [
                'cs' => 'jan-novak',
            ]),
        ]);

        self::assertSame(['https://example.com/videa'], $harness->locs('videa'));
        self::assertSame(['https://example.com/o-nas/jan-novak'], $harness->locs('lawyers'));
    }

    public function testListingOfAContentTypeWithoutPublishedRecordsIsLeftOut(): void
    {
        $products = Fixtures::contentType('products', [
            'locales' => [],
        ]);
        $harness = $this->harness([$products]);

        self::assertNull($harness->registry->resolve($harness->config, 'products'), 'an empty listing is a thin page');

        $harness->source->add(Fixtures::content(1, $products, [
            'cs' => 'okno',
        ]));
        $harness->registry->reset();
        self::assertSame(['https://example.com/products', 'https://example.com/product/okno'], $harness->locs('products'));
    }

    public function testExcludedContentTypesAndListings(): void
    {
        $pages = Fixtures::contentType('pages', [
            'locales' => [],
        ]);
        $entries = Fixtures::contentType('entries', [
            'singular_slug' => 'entry',
            'locales' => [],
        ]);
        $hidden = Fixtures::contentType('hidden', [
            'locales' => [],
            'hide_listing_from_xml_sitemap' => true,
        ]);
        $harness = $this->harness([$pages, $entries, $hidden], [
            Fixtures::content(1, $pages, [
                'cs' => 'a',
            ]),
            Fixtures::content(2, $entries, [
                'cs' => 'b',
            ]),
            Fixtures::content(3, $hidden, [
                'cs' => 'c',
            ]),
        ], [
            'exclude_contenttypes' => ['pages'],
            'exclude_listings' => ['entries'],
        ]);

        self::assertSame(['https://example.com/pages'], $harness->locs('pages'));
        self::assertSame(['https://example.com/entry/b'], $harness->locs('entries'));
        self::assertSame(['https://example.com/hidden/c'], $harness->locs('hidden'));
    }

    public function testImagesAreListedWithTheRecord(): void
    {
        $products = Fixtures::contentType('products', [
            'locales' => [],
        ]);
        $record = Fixtures::content(1, $products, [
            'cs' => 'okno',
        ]);
        Fixtures::addImage($record, 'image', 'okno.jpg');
        $harness = $this->harness([$products], [$record]);

        self::assertSame([], $harness->url('products', 'https://example.com/products')?->images, 'not on the listing');
        self::assertSame(['https://example.com/files/okno.jpg'], $harness->url('products', 'https://example.com/product/okno')?->images);
    }

    /**
     * eurodeck's 30 TemplateController pages with their `_locale` twins, which it
     * used to list by hand in the theme.
     */
    public function testStaticRoutes(): void
    {
        $routes = Fixtures::routes();
        $routes->add('okna', Fixtures::templateRoute('/okna-a-dvere', 'pages-static/okna-a-dvere.twig'));
        $routes->add('okna_locale', Fixtures::templateRoute('/{_locale}/okna-a-dvere', 'pages-static/okna-a-dvere.twig', [
            '_locale' => 'cs|en|de',
        ]));
        $routes->add('kariera', Fixtures::templateRoute('/kariera', 'pages-static/kariera.twig'));
        $routes->add('internal', Fixtures::templateRoute('/internal', 'pages-static/internal.twig'));

        $harness = $this->harness([], routes: $routes, config: [
            'exclude_static_routes' => ['internal'],
            'static_route_lastmod' => [
                'okna' => '2026-09-01',
            ],
        ], general: [
            'homepage' => 'missing',
        ]);

        self::assertSame([
            'https://example.com/okna-a-dvere',
            'https://example.com/en/okna-a-dvere',
            'https://example.com/de/okna-a-dvere',
            'https://example.com/kariera',
        ], $harness->locs('main'));
        self::assertSame('2026-09-01T00:00:00+00:00', $harness->urls('main')[1]->lastmodW3c());
        self::assertSame('https://example.com/okna-a-dvere', $harness->urls('main')[1]->alternates['x-default']);
        self::assertNull($harness->urls('main')[3]->lastmod, 'no date without static_route_lastmod: a template mtime is the deploy time');

        $harness->config = SitemapConfig::fromArray([
            'static_routes' => false,
        ]);
        self::assertSame([], $harness->locs('main'));
    }

    public function testTaxonomies(): void
    {
        $harness = $this->harness([], config: [
            'taxonomies' => ['tags', 'undefined'],
        ], taxonomies: [
            'tags' => [
                'slug' => 'tags',
                'singular_slug' => 'tag',
            ],
            'groups' => [
                'slug' => 'groups',
            ],
        ], taxonomySlugs: [
            'tags' => ['okna', 'dvere'],
        ]);

        self::assertSame(['https://example.com/tag/okna', 'https://example.com/tag/dvere'], $harness->locs('taxonomy-tags'));
        self::assertNull($harness->registry->resolve($harness->config, 'taxonomy-undefined'));
        self::assertSame('groups', $harness->registry->taxonomySingularSlug('groups'));
    }

    /**
     * portfolio: bolt-seo hides the `projekty` listing; a record's `noindex` drops
     * that locale and its alternates.
     */
    public function testBoltSeoNoindex(): void
    {
        $projekty = Fixtures::contentType('projekty', [
            'singular_slug' => 'projekty',
        ]);
        $hiddenInEnglish = Fixtures::content(1, $projekty, [
            'cs' => 'web',
            'en' => 'web',
        ]);
        Fixtures::addField($hiddenInEnglish, new Field(), 'seo', [
            'cs' => ['{"robots": "index, follow"}'],
            'en' => ['{"robots": "noindex"}'],
        ], 'cs');
        $seo = [
            'override_default' => [
                'projekty' => [
                    'robots' => 'noindex, follow',
                ],
            ],
        ];

        $harness = $this->harness([$projekty], [$hiddenInEnglish], seoConfig: $seo);
        self::assertSame(['https://example.com/projekty/web'], $harness->locs('projekty'));
        self::assertSame([], $harness->urls('projekty')[0]->alternates, 'the other locale is gone, so is the cluster');

        $harness->config = SitemapConfig::fromArray([
            'seo' => [
                'respect_noindex' => false,
            ],
        ]);
        self::assertCount(4, $harness->locs('projekty'));
    }

    public function testListingLastmodMovesForwardWhenARecordIsRemoved(): void
    {
        $clanky = Fixtures::contentType('clanky', [
            'locales' => [],
        ]);
        $harness = $this->harness([$clanky], [
            Fixtures::content(1, $clanky, [
                'cs' => 'a',
            ], '2026-10-01 10:00:00'),
            Fixtures::content(2, $clanky, [
                'cs' => 'b',
            ], '2026-10-02 10:00:00'),
        ]);

        self::assertSame('2026-10-02T10:00:00+00:00', $harness->url('clanky', 'https://example.com/clanky')?->lastmodW3c());

        $harness->source->remove(2);
        $harness->registry->reset();
        $harness->clock->modify('2026-10-20 09:00:00');

        $urls = $harness->urls('clanky');
        self::assertSame('https://example.com/clanky', $urls[0]->loc);
        self::assertSame('2026-10-20T09:00:00+00:00', $urls[0]->lastmodW3c(), 'the newest remaining record is older than the removal');
    }

    public function testRecordsAreSplitIntoChunks(): void
    {
        $clanky = Fixtures::contentType('clanky', [
            'singular_slug' => 'clanek',
            'locales' => ['cs', 'en'],
        ]);
        $records = [];
        foreach (range(1, 7) as $id) {
            $records[] = Fixtures::content($id, $clanky, [
                'cs' => 'c' . $id,
            ]);
        }

        // 6 URLs per file: room for the listing (2 locales) and 2 records × 2 locales.
        $harness = $this->harness([$clanky], $records, [
            'chunk_size' => 6,
        ]);

        self::assertSame(4, $harness->registry->resolve($harness->config, 'clanky')[0]->chunks ?? null);
        self::assertCount(6, $harness->urls('clanky'));
        self::assertSame(['https://example.com/clanek/c3', 'https://example.com/en/clanek/c3', 'https://example.com/clanek/c4', 'https://example.com/en/clanek/c4'], $harness->locs('clanky-2'));
        self::assertSame(['https://example.com/clanek/c7', 'https://example.com/en/clanek/c7'], $harness->locs('clanky-4'));
        self::assertNull($harness->registry->resolve($harness->config, 'clanky-5'));
        self::assertNull($harness->registry->resolve($harness->config, 'clanky-1'), 'the first file has no number');
    }

    public function testEventCanChangeTheUrls(): void
    {
        $harness = $this->harness([], general: [
            'homepage' => null,
        ]);
        $harness->dispatcher->addListener(SitemapUrlsEvent::class, static function (SitemapUrlsEvent $event): void {
            if ($event->segment->type === Segment::MAIN && $event->chunk === 1) {
                $event->addUrl(new SitemapUrl('https://example.com/cenik'));
                $event->setUrls(array_slice($event->getUrls(), 1));
            }
        });

        self::assertSame(['https://example.com/cenik'], $harness->locs('main'));
    }

    /**
     * @param list<ContentType> $contentTypes
     * @param list<Content> $records
     * @param array<string, mixed> $config
     * @param array<string, mixed> $general
     * @param array<string, mixed> $taxonomies
     * @param array<string, list<string>> $taxonomySlugs
     * @param array<string, mixed>|null $seoConfig
     */
    private function harness(array $contentTypes, array $records = [], array $config = [], array $general = [], ?RouteCollection $routes = null, array $taxonomies = [], array $taxonomySlugs = [], ?array $seoConfig = null): Harness
    {
        return new Harness($contentTypes, $records, $config, $general, $routes, $taxonomies, $taxonomySlugs, $seoConfig);
    }
}
