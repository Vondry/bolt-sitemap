<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use DOMDocument;
use LibXMLError;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Event\SitemapUrlsEvent;
use Tomvondracek\Sitemap\Segment\Segment;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\SitemapResponse;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Tomvondracek\Sitemap\Tests\Fixtures\Harness;
use Tomvondracek\Sitemap\Url\SitemapUrl;

/**
 * The rendered XML, checked against the sitemaps.org, Google image and XHTML
 * schemas and the sitemap protocol's rules.
 */
final class SitemapGeneratorTest extends TestCase
{
    private const XSD = __DIR__ . '/Fixtures/xsd';

    public function testIndexListsTheChildrenWithTheirLastmod(): void
    {
        $harness = $this->harness();

        $index = $harness->generator()->index($harness->config);

        self::assertSame(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
              <sitemap>
                <loc>https://example.com/sitemap-main.xml</loc>
                <lastmod>2026-10-02T08:51:00+00:00</lastmod>
              </sitemap>
              <sitemap>
                <loc>https://example.com/sitemap-clanky.xml</loc>
                <lastmod>2026-10-24T06:30:00+00:00</lastmod>
              </sitemap>
            </sitemapindex>

            XML
            , SitemapResponse::normalizeXml($index));
        $this->assertValid($index, 'siteindex-all.xsd');
    }

    public function testUrlset(): void
    {
        $harness = $this->harness();
        $harness->dispatcher->addListener(SitemapUrlsEvent::class, static function (SitemapUrlsEvent $event): void {
            $event->addUrl(new SitemapUrl('https://example.com/hledat?q=okna&page=2'));
        });

        $child = $harness->generator()->child($harness->config, ...$this->resolve($harness, 'clanky'));
        $xml = $child['body'];

        self::assertSame(7, $child['count']);
        self::assertSame('2026-10-24T06:30:00+00:00', $child['lastmod']);
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">', $xml);
        self::assertStringContainsString(<<<'XML'
              <url>
                <loc>https://example.com/en/clanek/okna-%26-dvere</loc>
                <lastmod>2026-10-24T06:30:00+00:00</lastmod>
                <xhtml:link rel="alternate" hreflang="cs" href="https://example.com/clanek/okna-a-dve%C5%99e"/>
                <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/clanek/okna-%26-dvere"/>
                <xhtml:link rel="alternate" hreflang="x-default" href="https://example.com/clanek/okna-a-dve%C5%99e"/>
                <image:image>
                  <image:loc>https://example.com/files/okna%20a%20dve%C5%99e.jpg</image:loc>
                </image:image>
              </url>
            XML
            , $xml);
        self::assertStringContainsString("<url>\n    <loc>https://example.com/hledat?q=okna&amp;page=2</loc>\n  </url>", $xml, 'entity-escaped');
        self::assertStringNotContainsString('changefreq', $xml);
        self::assertStringNotContainsString('priority', $xml);
        self::assertStringNotContainsString('xml-stylesheet', $xml);
        self::assertStringNotContainsString('image:title', $xml);
        $this->assertValid($xml, 'sitemap-all.xsd');
    }

    public function testUnusedNamespacesAreNotDeclared(): void
    {
        $pages = Fixtures::contentType('pages', [
            'locales' => [],
        ]);
        $harness = new Harness([$pages], [Fixtures::content(1, $pages, [
            'cs' => 'a',
        ])]);

        $xml = $harness->generator()->child($harness->config, ...$this->resolve($harness, 'pages'))['body'];

        self::assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        self::assertStringNotContainsString('<lastmod>', $xml, 'no invented dates');
        $this->assertValid($xml, 'sitemap-all.xsd');
    }

    /**
     * The stylesheet and the script for browsers, together or alone.
     */
    public function testHumanView(): void
    {
        $harness = $this->harness([
            'human_view' => [
                'stylesheet' => true,
                'script' => '/theme/site/sitemap.js',
            ],
        ]);

        $index = $harness->generator()->index($harness->config);
        $child = $harness->generator()->child($harness->config, ...$this->resolve($harness, 'clanky'))['body'];

        foreach ([$index, $child] as $xml) {
            self::assertStringContainsString("?>\n<?xml-stylesheet type=\"text/css\" href=\"/sitemap.css\"?>\n<", $xml);
            self::assertStringContainsString('<script xmlns="http://www.w3.org/1999/xhtml" type="text/javascript" src="/theme/site/sitemap.js"></script>', $xml);
        }

        self::assertLessThan(mb_strpos($child, '<url>'), mb_strpos($child, '<script'), 'the script comes before the first <url>');
        $this->assertValid($index, 'siteindex-all.xsd');
        $this->assertValid($child, 'sitemap-all.xsd');

        $harness->config = SitemapConfig::fromArray([
            'human_view' => [
                'script' => true,
            ],
        ]);
        self::assertStringContainsString('src="/sitemap-ui.js"', $harness->generator()->index($harness->config, false));
    }

    public function testRemovingAUrlMovesTheChildLastmodForward(): void
    {
        $harness = $this->harness();
        $generator = $harness->generator();
        $generator->index($harness->config, false);

        $harness->source->remove(11);
        $harness->registry->reset();
        $harness->clock->modify('2026-10-26 12:00:00');

        $child = $generator->child($harness->config, ...$this->resolve($harness, 'clanky'), cached: false);
        self::assertSame('2026-10-26T12:00:00+00:00', $child['lastmod']);
        self::assertStringContainsString('<lastmod>2026-10-26T12:00:00+00:00</lastmod>', $generator->index($harness->config, false));
    }

    public function testCachedUntilInvalidated(): void
    {
        $harness = $this->harness([
            'listings' => false,
        ]);
        $generator = $harness->generator();
        $before = $generator->index($harness->config);
        $clanky = $this->resolve($harness, 'clanky');

        $harness->source->remove(10);
        $harness->source->remove(11);
        $harness->registry->reset();
        self::assertSame($before, $generator->index($harness->config), 'cached');
        self::assertSame(4, $generator->child($harness->config, ...$clanky)['count']);

        $harness->cache->invalidate();
        self::assertStringNotContainsString('sitemap-clanky.xml', $generator->index($harness->config), 'an empty child is left out');
    }

    public function testThemeTemplateOverridesTheShippedOne(): void
    {
        $theme = $this->harness();
        mkdir($theme->projectDir . '/public/theme/base-2021', 0o777, true);
        file_put_contents($theme->projectDir . '/public/theme/base-2021/sitemap-index.xml.twig', "\n<?xml version=\"1.0\"?><sitemapindex>{{ sitemaps|length }}</sitemapindex>");

        $index = $theme->generator()->index($theme->config, false);

        self::assertSame("\n<?xml version=\"1.0\"?><sitemapindex>2</sitemapindex>", $index);
        self::assertSame("<?xml version=\"1.0\"?><sitemapindex>2</sitemapindex>\n", SitemapResponse::normalizeXml($index), 'the response removes what precedes <?xml');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function harness(array $config = []): Harness
    {
        $kontakt = Fixtures::contentType('kontakt', [
            'singleton' => true,
            'locales' => ['cs'],
        ]);
        $clanky = Fixtures::contentType('clanky', [
            'singular_slug' => 'clanek',
        ]);

        $article = Fixtures::content(10, $clanky, [
            'cs' => 'okna-a-dveře',
            'en' => 'okna-&-dvere',
        ], '2026-10-24 06:30:00');
        Fixtures::addImage($article, 'image', 'okna a dveře.jpg');

        return new Harness([$kontakt, $clanky], [
            Fixtures::content(1, $kontakt, [
                'cs' => 'kontakt',
            ], '2026-10-02 08:51:00'),
            $article,
            Fixtures::content(11, $clanky, [
                'cs' => 'druhy',
            ], '2026-10-20 06:30:00'),
        ], $config);
    }

    /**
     * @return array{0: Segment, 1: int}
     */
    private function resolve(Harness $harness, string $name): array
    {
        $resolved = $harness->registry->resolve($harness->config, $name);
        self::assertNotNull($resolved);

        return $resolved;
    }

    private function assertValid(string $xml, string $schema): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml), 'well-formed');

        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->schemaValidate(self::XSD . '/' . $schema);
            $errors = array_map(static fn (LibXMLError $error): string => mb_trim($error->message), libxml_get_errors());
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        self::assertTrue($valid, implode("\n", $errors));
    }
}
