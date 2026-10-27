# XML Sitemap for Bolt CMS

Serves a sitemap index at `/sitemap.xml` for a Bolt 6 site, generated from its content and routes, following the
[sitemaps.org protocol](https://www.sitemaps.org/protocol.html) 0.9 and Google's image and `hreflang` extensions. It
replaces `bolt/sitemap-extension`. Without any theme override it gets these right:

- **one `<url>` per page and language**, each with the complete, reciprocal set of `hreflang` alternates, including
  itself and `x-default`
- **singletons at their slugless page** (`/kontakt`, not `/kontakt/kontakt-2` plus a duplicate listing entry), and
  their alternates too. A singleton with its own `record_route` (such as `privacy_locale` with a fixed path) keeps
  that route.
- **listing pages** with alternates on the listing route (`/en/clanky`, not a record's link with its slug cut off)
- **static pages**: the routes in `config/routes.yaml` that render a template with Bolt's `TemplateController`,
  together with their `<name>_locale` twins. Nothing has to be listed by hand.
- the homepage record as `/`, listed once
- **honest `<lastmod>`**:
  - a record's modification date, in UTC;
  - for a listing or a child sitemap, also the last time a record was added or removed;
  - no date at all where none is known (no `now`, and no template file times, which are deploy times)
- no `<changefreq>`, `<priority>`, `image:title` or XSLT. Image and `xhtml` namespaces are declared only where they
  are used.
- with [bolt-seo](https://github.com/AppoloDev/bolt-seo) installed (optional), pages it marks `noindex` or gives
  another canonical URL are left out
- absolute URLs on the canonical host (`general/canonical`), percent-encoded, with Punycode hosts
- a sitemap index with one child sitemap per ContentType, split at 10,000 URLs, so Search Console reports coverage
  per segment
- `application/xml; charset=UTF-8`, `X-Robots-Tag: noindex` and a content-hash ETag (`304 Not Modified`). The XML is
  kept in Bolt's cache until content changes.
- optionally, a readable view for people who open the sitemap in a browser, made without XSLT: a stylesheet, a script
  rendering a table with links, or your own files

## Requirements

- Bolt CMS `^6.0`, PHP `>=8.2` with the `dom` and `libxml` extensions

## Installation

```bash
composer remove bolt/sitemap-extension   # the two can't be installed together: both serve /sitemap.xml
composer require tomvondracek/bolt-sitemap
bin/console extensions:configure --with-config
bin/console cache:clear
```

`extensions:configure` copies the routes and the `@bolt-sitemap` Twig namespace into the project
(`config/routes/extension_bolt-sitemap.yaml`, `config/packages/extension_bolt-sitemap.yaml`). `--with-config` copies
the configuration to `config/extensions/tomvondracek-sitemap.yaml`.

Point `robots.txt` at the index and submit it in Google Search Console, Bing Webmaster Tools and Seznam Webmaster:

```text
Sitemap: https://example.com/sitemap.xml
```

Remove a static `public/sitemap.xml` if there is one: Apache and nginx serve existing files before Symfony gets the
request. For the same reason, don't add an nginx `location` for `sitemap*.xml`.

## What is listed

`/sitemap.xml` is a `<sitemapindex>` that points to these child sitemaps:

| File | Contents |
|---|---|
| `/sitemap-main.xml` | The homepage, every singleton with a page, and the static routes |
| `/sitemap-<slug>.xml` | A ContentType: its listing page and its published records. Past `chunk_size` URLs it continues in `/sitemap-<slug>-2.xml`, `-3`, … |
| `/sitemap-taxonomy-<slug>.xml` | The pages of a taxonomy named in `taxonomies` |

A child sitemap without URLs is left out of the index.

| Page | Listed | URL |
|---|---|---|
| Homepage record (`homepage: homepage` or `pages/5`) | always, even when `viewless` | `/`, `/en/`, … |
| No `homepage` setting | yes, without `<lastmod>` | `/` |
| `homepage: <contenttype>` (a listing) | no: the listing page is the canonical one | — |
| Singleton | unless `viewless` or in `exclude_contenttypes` | its listing route: `/kontakt`, `/en/kontakt` |
| Singleton with its own `record_route` | the same | that route, as is |
| Record | published; unless the ContentType is `viewless` or in `exclude_contenttypes` | its `record_route` with its slug in each locale |
| Listing page | unless `listings: false`, a singleton, `viewless_listing`, `hide_listing_from_xml_sitemap` or in `exclude_listings` | `/clanky`, `/en/clanky` |
| Static route | `TemplateController` routes without parameters, unless `static_routes: false` or in `exclude_static_routes` | the route, plus its `{_locale}` twin of the same template for the other languages |
| Taxonomy page | for each taxonomy in `taxonomies` | `/<singular slug>/<term>` |

A page is listed in every language of its ContentType's `locales`, each language as its own `<url>`. URLs in the
default locale have no prefix (`remove_default_locale_on_canonical`, on by default). The `x-default` alternate is the
default locale's URL.

`<lastmod>` is:

- **a record:** its modification date;
- **a listing page:** the later of its newest record's modification and the last time a record was added or removed;
- **a static route:** the date in `static_route_lastmod`, otherwise none;
- **the homepage without a record, and taxonomy pages:** none;
- **a child sitemap in the index:** the later of its newest `<lastmod>` and the last time a URL was added to it or
  removed from it.

The times of additions and removals are kept in `var/data/tomvondracek-sitemap.json`, outside the cache, so
`cache:clear` doesn't reset them. A set of URLs seen for the first time has no known change date, so only the URLs'
own dates count. No date is ever invented.

### Images

By default a record lists its first image, the one Bolt's `|image` filter returns. To list more, name the image and
imagelist fields per ContentType:

```yaml
images:
  fields:
    products: [image, gallery, technical_drawing]
```

An empty list lists none (e.g. `homepage: []` when its first image is a decorative badge). At most 1,000 images
per URL, each once. Only list images that are content, not logos or icons.

### With bolt-seo (optional)

The extension works on its own and never requires [appolodev/bolt-seo](https://github.com/AppoloDev/bolt-seo).
Without it, every published page with a URL is listed. With it installed, nothing needs to be configured; the
sitemap simply follows what editors set there.

**What you get:** the sitemap and the pages never send search engines conflicting signals. A page that
bolt-seo renders with `noindex` (or `none`), or with a canonical URL pointing to another page, is left out of the
sitemap:

- an editor sets **Robots: noindex** in a record's SEO tab, e.g. for a thank-you page, and its URL leaves the
  sitemap;
- an editor points a record's **canonical URL** at another page, e.g. a duplicate article, and only the original is
  listed;
- `override_default` marks a listing page or a static route `noindex` (portfolio: the `projekty` listing), and it
  leaves the sitemap too;
- the decision is made per language: when only the English version is `noindex`, only that URL is dropped, along
  with its hreflang alternates. The Czech page stays.

Without bolt-seo you would have to repeat each of these in `exclude_*` here, and keep the two in sync by hand. To
list every page anyway, set `seo: { respect_noindex: false }`.

**What the sitemap reads from bolt-seo.** These are files and values, not bolt-seo's PHP classes, so bolt-seo is
never loaded by the sitemap:

| What | Used for |
|---|---|
| the class `Appolo\BoltSeo\Extension` exists | detecting that bolt-seo is installed; otherwise everything else is ignored, including a leftover config file |
| `config/extensions/appolo-boltseo.yaml` (+ `_local`) | bolt-seo's config |
| `default.robots` | the site-wide robots value |
| `override_default.<key>.robots`, `.canonical`, `.locales.<locale>` | overrides by route name (`homepage` also covers `homepage_locale`) or, for a listing page, by ContentType slug |
| a record field named `seo`, holding JSON with `robots` and `canonical` | a record's own values, in each language |

They are combined the way bolt-seo combines them: the override first, then the record's field, then the default. A
canonical URL in `default` is ignored on purpose: it would point every page at one address.

`BoltSeoContractTest` keeps the two in step. For each case it runs bolt-seo's own `Seo` class to render the page's
robots and canonical tags, and checks that the sitemap reaches the same decision. CI runs it against the newest
bolt-seo release, against bolt-seo's main branch, and every week, plus once without bolt-seo installed. If bolt-seo
changes how it decides, that test fails.

## Configuration

`config/extensions/tomvondracek-sitemap.yaml`, every key optional. A `tomvondracek-sitemap_local.yaml` next to it
overrides single values.

```yaml
listings: true                # listing pages of ContentTypes
exclude_contenttypes: []      # ContentTypes whose records are left out
exclude_listings: []          # ContentTypes whose listing page is left out
taxonomies: []                # e.g. [categories, tags]
static_routes: true           # TemplateController routes from config/routes.yaml
exclude_static_routes: []     # route names
static_route_lastmod: {}      # route name => date, e.g. { about: 2026-10-01 }
main_segment: main            # name of /sitemap-<name>.xml with homepage, singletons, static routes
chunk_size: 10000             # most URLs per child sitemap (the protocol allows 50000)
max_age: 3600                 # seconds crawlers, proxies and the server cache keep a sitemap; 0 = no caching
hreflang:
  x_default: true
images:
  enabled: true
  fields: {}                  # ContentType => image/imagelist fields; default: the first image
seo:
  respect_noindex: true
human_view:
  stylesheet: false           # true = /sitemap.css, or the URL of your own CSS
  script: false               # true = /sitemap-ui.js, or the URL of your own script
templates:
  index: sitemap-index.xml.twig
  urlset: sitemap-urlset.xml.twig
  stylesheet: sitemap.css.twig
  script: sitemap-ui.js.twig
```

An unknown key or a value of the wrong type is an error that names the problem. An empty value means the default.

## Showing the sitemap in a browser

Crawlers read the XML and ignore everything below. By default a browser shows the raw XML. Chrome
removes XSLT on November 17, 2026, so the extension never uses it. There are two opt-in alternatives:

- **`human_view: { stylesheet: true }`** adds `<?xml-stylesheet type="text/css" href="/sitemap.css"?>`: a styled list
  of URLs and dates. No JavaScript, but the URLs are not links. It also adds an XHTML viewport `<meta>`, without
  which phones lay the XML out at desktop width.
- **`human_view: { script: true }`** adds an XHTML `<script>` as the first element. It renders a table with links,
  images and `hreflang` pills, 1,000 rows at a time. With a strict Content Security Policy, allow
  `/sitemap-ui.js`.

Both can be on at once, and either can be the URL of your own file instead of `true`
(`stylesheet: /theme/my-theme/sitemap.css`). To change the bundled ones, copy `sitemap.css.twig` or
`sitemap-ui.js.twig` into your theme. The sitemap is the same XML for every `Accept` header and user agent: there is
no content negotiation.

## Customizing

### Templates

The XML is rendered from Twig templates, looked up in your theme first. To change one, copy it from the extension's
`templates/` into the theme (or name another file under `templates:`):

| Template | Variables |
|---|---|
| `sitemap-index.xml.twig` | `sitemaps` (`loc`, `lastmod`), `stylesheet`, `script` |
| `sitemap-urlset.xml.twig` | `urls` (`loc`, `lastmodW3c`, `alternates`, `images`), `hasImages`, `hasAlternates`, `stylesheet`, `script` |

Nothing may precede `<?xml`: start the template with `{#- … -#}` or `{%- … -%}`. The response also strips a BOM or
blank lines before the declaration.

### Adding or removing URLs

For most changes an event listener is simpler than a template. `SitemapUrlsEvent` is dispatched for every child
sitemap before it is rendered:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Tomvondracek\Sitemap\Event\SitemapUrlsEvent;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\Url\SitemapUrl;

#[AsEventListener]
final class SitemapListener
{
    public function __invoke(SitemapUrlsEvent $event): void
    {
        if ($event->segment->type === Segment::MAIN) {
            $event->addUrl(new SitemapUrl('https://example.com/cenik'));
        }

        $event->setUrls(array_filter($event->getUrls(), static fn (SitemapUrl $url): bool => ! str_contains($url->loc, '/interni/')));
    }
}
```

`SitemapUrl::cluster()` builds the per-language URLs with their alternates from a `locale => URL` map. URLs must be
absolute and on the site's host. The result is cached, so a listener must not depend on the request.

## Caching

Each sitemap is kept for `max_age` seconds in Bolt's `cache.app` pool, tagged `tomvondracek_sitemap`, per host and
config:

- Saving, deleting or changing the status of content drops it at once: records, field values and translations,
  taxonomies, relations or media, whether changed in the editor, with bulk actions or through the API.
- Bolt's timed publishing and depublishing is a plain SQL update without events. It shows up once the cached copy
  expires.
- Logged-in users and debug mode always get a fresh sitemap.
- To drop only the sitemaps: `bin/console cache:pool:invalidate-tags tomvondracek_sitemap`.

Browsers and crawlers revalidate with the ETag, which changes on additions, edits and removals alike. There is
deliberately no `Last-Modified` header: taken from the newest `<lastmod>`, it would hide removals.

## Migrating from bolt/sitemap-extension

1. `composer remove bolt/sitemap-extension`, then install this extension as above. Afterwards check that the other
   extensions' `config/packages/extension_*.yaml` and `config/routes/extension_*.yaml` are still there: when Bolt's
   post-update script can't boot the container in the middle of the swap, it removes them. Run
   `bin/console extensions:configure` again to restore them.
2. Delete the theme's copy of the sitemap template (e.g. `extensions/_sitemap.xml.twig`) and
   `config/extensions/bolt-sitemapextension.yaml`.
3. Carry the old options over:

| bolt/sitemap-extension | here |
|---|---|
| `show_listings: true` | `listings: true` (the default) |
| `limit` | `chunk_size` (more records are split, not cut off) |
| `taxonomies` | `taxonomies` |
| `exclude_contenttypes`, `exclude_listings` | the same |
| `hide_listing_from_xml_sitemap` (ContentType) | still honoured |
| `templates.xml` | `templates.urlset` (only for a real customization) |
| `templates.xsl`, `/sitemap.xsl` | gone; see [Showing the sitemap in a browser](#showing-the-sitemap-in-a-browser) |
| a hand-written list of static routes | `static_routes: true` (the default) |
| `record_route: pagebinding` | still honoured |

`/sitemap.xml` is now an index rather than a single list. Search engines follow it, so a submitted `/sitemap.xml`
keeps working.

## Checking a deployment

For example:

```bash
curl -s https://example.com/sitemap.xml | head -c 5 | xxd      # 3c3f 786d 6c: <?xml
curl -sI https://example.com/sitemap.xml                       # 200, application/xml, X-Robots-Tag, ETag
curl -s https://example.com/sitemap-main.xml | xmllint --noout -
```

The schemas for a full validation are in `tests/Fixtures/xsd/` (`sitemap-all.xsd`, `siteindex-all.xsd`).

## Development

```bash
composer install

vendor/bin/phpunit
vendor/bin/ecs check src tests
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/rector process --dry-run
```

To try it in a Bolt project from a local checkout:

```bash
composer config repositories.bolt-sitemap '{"type":"path","url":"../bolt-sitemap"}'
composer remove bolt/sitemap-extension
composer require tomvondracek/bolt-sitemap:@dev
bin/console extensions:configure --with-config
```

### Layout

| Path | What |
|---|---|
| `src/Controller/SitemapController.php` | Routes: `/sitemap.xml`, `/sitemap-<name>.xml`, `/sitemap.css`, `/sitemap-ui.js` |
| `src/SitemapGenerator.php` | Index and child sitemaps: rendering, `<lastmod>` of each child, caching |
| `src/Segment/` | The segments of the index and their chunks (`SegmentRegistry`); the URLs of each (`SegmentUrls`) |
| `src/Content/` | Bolt content: records (`ContentSource`, Doctrine), homepage, routes of records and listings, static routes, images, bolt-seo rules |
| `src/Url/` | URLs: per-locale route URLs, canonical origin, normalization, the `SitemapUrl` value |
| `src/State/StateStore.php` | When a set of URLs last changed, for removal-aware `<lastmod>` |
| `src/SitemapResponse.php`, `src/EventSubscriber/` | Headers, ETag, 304; `private` when a cookie is set |
| `src/SitemapCache.php`, `src/EventListener/ContentChangeListener.php` | Server-side cache; dropped on content changes |
| `src/SitemapConfig.php`, `src/SitemapConfigLoader.php` | Typed config with defaults, loaded like Bolt's `ConfigTrait` |
| `templates/` | Shipped templates (`@bolt-sitemap/…`) |

## License

MIT
