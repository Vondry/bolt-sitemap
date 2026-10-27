<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use Bolt\Entity\Field;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Sitemap\Content\SeoRules;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class SeoRulesTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/bolt-sitemap-seo-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/config/extensions', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * A leftover SEO field or bolt-seo config file changes nothing while bolt-seo
     * is not installed.
     */
    public function testNothingIsExcludedWithoutBoltSeo(): void
    {
        $this->writeSeoConfig([
            'default' => [
                'robots' => 'noindex',
            ],
        ]);
        $record = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addSeo($record, [
            'robots' => 'noindex',
        ]);

        $rules = new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl(), false);

        self::assertFalse($rules->excludes('https://example.com/page/a', 'record', 'cs', $record));
        self::assertFalse($rules->excludes('https://example.com/', 'homepage', 'cs'));
    }

    /**
     * Without an explicit answer, whether bolt-seo is installed is detected.
     */
    public function testDetectsWhetherBoltSeoIsInstalled(): void
    {
        $this->writeSeoConfig([
            'default' => [
                'robots' => 'noindex',
            ],
        ]);

        $rules = new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl());

        self::assertSame(class_exists(SeoRules::SEO_EXTENSION), $rules->excludes('https://example.com/', 'homepage', 'cs'));
    }

    public function testRecordSeoField(): void
    {
        $noindex = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addSeo($noindex, [
            'robots' => 'noindex, follow',
        ]);
        $indexed = Fixtures::content(2, Fixtures::contentType('pages'));
        Fixtures::addSeo($indexed, [
            'robots' => 'index, follow',
            'title' => 'About',
        ]);
        $broken = Fixtures::content(3, Fixtures::contentType('pages'));
        Fixtures::addField($broken, new Field(), 'seo', [
            'cs' => ['{not json'],
        ], 'cs');

        $rules = $this->rules([]);

        self::assertTrue($rules->excludes('https://example.com/page/a', 'record', 'cs', $noindex));
        self::assertFalse($rules->excludes('https://example.com/page/b', 'record', 'cs', $indexed));
        self::assertFalse($rules->excludes('https://example.com/page/c', 'record', 'cs', $broken));
        self::assertFalse($rules->excludes('https://example.com/page/d', 'record', 'cs', Fixtures::content(4, Fixtures::contentType('pages'))));
    }

    public function testCanonicalElsewhere(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addSeo($record, [
            'canonical' => '/page/original',
        ]);

        $rules = $this->rules([]);

        self::assertTrue($rules->excludes('https://example.com/page/copy', 'record', 'cs', $record));
        self::assertFalse($rules->excludes('https://example.com/page/original/', 'record', 'cs', $record), 'a trailing slash is the same page');
    }

    /**
     * portfolio: `override_default.projekty.robots: 'noindex, follow'` hides the
     * `projekty` listing.
     */
    public function testListingOverrideByContentTypeSlug(): void
    {
        $rules = $this->rules([
            'override_default' => [
                'projekty' => [
                    'title' => 'Práce na zakázku',
                    'robots' => 'noindex, follow',
                    'locales' => [
                        'en' => [
                            'robots' => 'index, follow',
                        ],
                    ],
                ],
            ],
        ]);

        self::assertTrue($rules->excludes('https://example.com/projekty', 'listing', 'cs', listing: 'projekty'));
        self::assertFalse($rules->excludes('https://example.com/en/projekty', 'listing_locale', 'en', listing: 'projekty'), 'the locale override wins');
        self::assertFalse($rules->excludes('https://example.com/clanky', 'listing', 'cs', listing: 'clanky'));
    }

    public function testOverrideByRouteNameBeatsTheRecordField(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('homepage'));
        Fixtures::addSeo($record, [
            'robots' => 'index, follow',
        ]);

        $rules = $this->rules([
            'override_default' => [
                'homepage' => [
                    'robots' => 'none',
                ],
                'broken' => 'Home',
            ],
        ]);

        self::assertTrue($rules->excludes('https://example.com/', 'homepage', 'cs', $record));
        self::assertTrue($rules->excludes('https://example.com/en/', 'homepage_locale', 'en', $record), '<name>_locale falls back to <name>');
        self::assertFalse($rules->excludes('https://example.com/x', 'broken', 'cs'), 'an override that is not a map sets nothing');
    }

    public function testSiteWideDefaultRobots(): void
    {
        $rules = $this->rules([
            'default' => [
                'robots' => 'noindex',
                'canonical' => 'https://example.com/',
            ],
        ]);

        self::assertTrue($rules->excludes('https://example.com/about', 'about', 'cs'));

        $record = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addSeo($record, [
            'robots' => 'index',
        ]);
        self::assertFalse($rules->excludes('https://example.com/page/a', 'record', 'cs', $record), 'the record field wins over the default; the default canonical is ignored');
    }

    public function testConfigIsReadOncePerRequest(): void
    {
        $rules = $this->rules([
            'default' => [
                'robots' => 'noindex',
            ],
        ]);
        self::assertTrue($rules->excludes('https://example.com/', 'homepage', 'cs'));

        $this->writeSeoConfig([]);
        self::assertTrue($rules->excludes('https://example.com/', 'homepage', 'cs'), 'still the config read before');

        $rules->reset();
        self::assertFalse($rules->excludes('https://example.com/', 'homepage', 'cs'));
    }

    /**
     * @param array<string, mixed>|null $seoConfig null = bolt-seo not installed
     */
    private function rules(?array $seoConfig): SeoRules
    {
        if ($seoConfig !== null) {
            $this->writeSeoConfig($seoConfig);
        }

        return new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl(), $seoConfig !== null);
    }

    /**
     * @param array<string, mixed> $seoConfig
     */
    private function writeSeoConfig(array $seoConfig): void
    {
        file_put_contents($this->projectDir . '/config/extensions/appolo-boltseo.yaml', Yaml::dump($seoConfig, 6));
    }
}
