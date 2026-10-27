<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use Appolo\BoltSeo\Extension as BoltSeoExtension;
use Appolo\BoltSeo\Field\SeoField;
use Appolo\BoltSeo\Seo\Seo;
use Bolt\Configuration\Config;
use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Composer\InstalledVersions;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tomvondracek\Sitemap\Content\SeoRules;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The contract with appolodev/bolt-seo, checked against the installed release.
 *
 * SeoRules reads bolt-seo's config and SEO fields itself, so the sitemap works
 * without bolt-seo installed and never depends on its internal classes at
 * runtime. This test keeps the two in step: for every case, bolt-seo's own
 * `Seo` class decides the page's robots and canonical tags, and the sitemap must
 * agree on whether the page belongs in it. CI runs it against the newest release
 * and bolt-seo's main branch every week.
 *
 * What the sitemap relies on:
 *  - the extension class `Appolo\BoltSeo\Extension` (to detect bolt-seo);
 *  - its config file `config/extensions/appolo-boltseo.yaml` (+ `_local`);
 *  - the config keys `default.robots` and
 *    `override_default.<route name | ContentType slug>.{robots, canonical, locales.<locale>}`;
 *  - a record field named `seo` holding JSON with `robots` and `canonical`;
 *  - the order: override, then the record's field, then the default.
 */
#[RequiresMethod(Seo::class, 'robots')]
final class BoltSeoContractTest extends TestCase
{
    private const ORIGIN = 'https://example.com';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/bolt-sitemap-contract-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/config/extensions', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * Bolt registers an extension by its package's `extra.entrypoint`: when that
     * class exists, bolt-seo is installed.
     */
    public function testDetectedByItsEntrypoint(): void
    {
        $path = InstalledVersions::getInstallPath('appolodev/bolt-seo');
        self::assertIsString($path);
        $composer = json_decode((string) file_get_contents($path . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['extra'] ?? null);

        self::assertSame(SeoRules::SEO_EXTENSION, $composer['extra']['entrypoint'] ?? null);
    }

    /**
     * Bolt derives the config filename from the extension's namespace; SeoRules
     * must read the same file bolt-seo does.
     */
    public function testReadsTheConfigFileBoltSeoReads(): void
    {
        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn($this->projectDir . '/config/extensions');
        $container = new Container();
        $container->set(Config::class, $config);

        $extension = new BoltSeoExtension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);
        $filenames = $extension->getConfigFilenames();
        self::assertIsString($filenames['main'] ?? null);
        self::assertIsString($filenames['local'] ?? null);

        self::assertSame(SeoRules::SEO_CONFIG_BASENAME . '.yaml', basename($filenames['main']));
        self::assertSame(SeoRules::SEO_CONFIG_BASENAME . '_local.yaml', basename($filenames['local']));
    }

    public function testShippedConfigHasTheKeysTheSitemapReads(): void
    {
        $file = dirname((string) (new ReflectionClass(BoltSeoExtension::class))->getFileName(), 2) . '/config/config.yaml';
        $config = Yaml::parseFile($file);

        self::assertIsArray($config);
        self::assertIsArray($config['default'] ?? null);
        self::assertIsString($config['default']['robots'] ?? null, '`default.robots` is the site-wide robots value');
        self::assertStringContainsString('override_default:', (string) file_get_contents($file), '`override_default` is documented');
    }

    public function testSeoFieldType(): void
    {
        self::assertSame('seo', (new ReflectionClassConstant(SeoField::class, 'TYPE'))->getValue(), 'the field type editors add as `seo: { type: seo }`');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null, 3: array<string, mixed>, 4: array<string, array<string, string>|string>|null}>
     *         url, route, listing ContentType slug, bolt-seo config, `seo` field JSON by locale
     */
    public static function pages(): iterable
    {
        $noindex = [
            'robots' => 'noindex, follow',
        ];

        yield 'nothing configured' => ['/page/about', 'record', null, [], null];
        yield 'shipped default' => ['/page/about', 'record', null, [
            'default' => [
                'robots' => 'index, follow',
            ],
        ], null];
        yield 'site-wide noindex default' => ['/page/about', 'record', null, [
            'default' => $noindex,
        ], null];
        yield 'robots none' => ['/page/about', 'record', null, [
            'default' => [
                'robots' => 'none',
            ],
        ], null];
        yield 'record field noindex' => ['/page/about', 'record', null, [], [
            'cs' => $noindex,
        ]];
        yield 'record field beats the default' => ['/page/about', 'record', null, [
            'default' => $noindex,
        ], [
            'cs' => [
                'robots' => 'index, follow',
            ],
        ]];
        yield 'record field in another locale' => ['/en/page/about', 'record_locale', null, [], [
            'cs' => [
                'robots' => 'index, follow',
            ],
            'en' => $noindex,
        ]];
        yield 'record field canonical elsewhere' => ['/page/copy', 'record', null, [], [
            'cs' => [
                'canonical' => self::ORIGIN . '/page/original',
            ],
        ]];
        yield 'record field canonical of itself' => ['/page/original', 'record', null, [], [
            'cs' => [
                'canonical' => self::ORIGIN . '/page/original',
            ],
        ]];
        yield 'unreadable record field' => ['/page/about', 'record', null, [
            'default' => [
                'robots' => 'index',
            ],
        ], [
            'cs' => '{not json',
        ]];
        yield 'override by route beats the record field' => ['/', 'homepage', null, [
            'override_default' => [
                'homepage' => $noindex,
            ],
        ], [
            'cs' => [
                'robots' => 'index, follow',
            ],
        ]];
        yield 'override of <name> covers <name>_locale' => ['/en/', 'homepage_locale', null, [
            'override_default' => [
                'homepage' => $noindex,
            ],
        ], null];
        yield 'override with a locale block' => ['/en/', 'homepage_locale', null, [
            'override_default' => [
                'homepage' => $noindex + [
                    'locales' => [
                        'en' => [
                            'robots' => 'index, follow',
                        ],
                    ],
                ],
            ],
        ], null];
        yield 'listing override by ContentType slug (portfolio)' => ['/projekty', 'listing', 'projekty', [
            'override_default' => [
                'projekty' => $noindex,
            ],
        ], null];
        yield 'listing override in another locale' => ['/en/projekty', 'listing_locale', 'projekty', [
            'override_default' => [
                'projekty' => $noindex + [
                    'locales' => [
                        'en' => [
                            'robots' => 'index, follow',
                        ],
                    ],
                ],
            ],
        ], null];
        yield 'route override beats the ContentType slug' => ['/projekty', 'listing', 'projekty', [
            'override_default' => [
                'listing' => [
                    'robots' => 'index, follow',
                ],
                'projekty' => $noindex,
            ],
        ], null];
        yield 'ContentType slug of another listing' => ['/clanky', 'listing', 'clanky', [
            'override_default' => [
                'projekty' => $noindex,
            ],
        ], null];
        yield 'override canonical elsewhere' => ['/o-nas', 'about', null, [
            'override_default' => [
                'about' => [
                    'canonical' => self::ORIGIN . '/about-us',
                ],
            ],
        ], null];
        yield 'override that is not a map' => ['/', 'homepage', null, [
            'override_default' => [
                'homepage' => 'Home',
            ],
        ], null];
    }

    /**
     * @param array<string, mixed> $seoConfig
     * @param array<string, array<string, string>|string>|null $seoField
     */
    #[DataProvider('pages')]
    public function testTheSitemapAgreesWithTheTagsBoltSeoRenders(string $path, string $route, ?string $listing, array $seoConfig, ?array $seoField): void
    {
        $url = self::ORIGIN . $path;
        $locale = str_starts_with($path, '/en/') || $path === '/en' ? 'en' : 'cs';
        file_put_contents($this->projectDir . '/config/extensions/' . SeoRules::SEO_CONFIG_BASENAME . '.yaml', Yaml::dump($seoConfig, 8));

        $record = $seoField === null ? null : $this->record($seoField);

        $seo = $this->boltSeo($url, $route, $locale, $listing, $record);
        $robots = $seo->robots();
        $canonical = $seo->canonical();
        $boltSeoKeepsItOut = preg_match('/\b(noindex|none)\b/i', $robots) === 1
            || mb_rtrim($canonical, '/') !== mb_rtrim($url, '/');

        $rules = new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl(), true);

        self::assertSame(
            $boltSeoKeepsItOut,
            $rules->excludes($url, $route, $locale, $record, $listing),
            sprintf('bolt-seo renders robots "%s" and canonical "%s" for %s', $robots, $canonical, $url)
        );
    }

    /**
     * The one intended difference: a canonical URL in `default` would point every
     * page at one address, a misconfiguration the sitemap doesn't follow.
     */
    public function testASiteWideDefaultCanonicalIsIgnored(): void
    {
        file_put_contents($this->projectDir . '/config/extensions/' . SeoRules::SEO_CONFIG_BASENAME . '.yaml', Yaml::dump([
            'default' => [
                'canonical' => self::ORIGIN . '/',
            ],
        ]));
        $url = self::ORIGIN . '/page/about';

        self::assertSame(self::ORIGIN . '/', $this->boltSeo($url, 'record', 'cs', null, null)->canonical());
        self::assertFalse((new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl(), true))->excludes($url, 'record', 'cs'));
    }

    /**
     * bolt-seo only reads the field named `seo`.
     */
    public function testAnSeoFieldOfAnotherNameIsIgnored(): void
    {
        file_put_contents($this->projectDir . '/config/extensions/' . SeoRules::SEO_CONFIG_BASENAME . '.yaml', Yaml::dump([]));
        $record = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addField($record, new Field(), 'meta', [
            'cs' => ['{"robots": "noindex"}'],
        ], 'cs');
        $url = self::ORIGIN . '/page/about';

        self::assertSame('index, follow', $this->boltSeo($url, 'record', 'cs', null, $record)->robots());
        self::assertFalse((new SeoRules(new SitemapConfigLoader(Fixtures::config([], $this->projectDir)), Fixtures::siteUrl(), true))->excludes($url, 'record', 'cs', $record));
    }

    /**
     * bolt-seo as it runs on the page: the request's route and locale, the record
     * as the `record` Twig global, its config from the project's file.
     */
    private function boltSeo(string $url, string $route, string $locale, ?string $listing, ?Content $record): Seo
    {
        $twig = new Environment(new ArrayLoader());
        if ($record instanceof Content) {
            $twig->addGlobal('record', $record);
            // Bolt loads a record's fields in the request's locale.
            if ($record->hasField('seo')) {
                $record->getField('seo')
                    ->setLocale($locale);
            }
        }

        $request = Request::create($url);
        $request->attributes->set('_route', $route);
        if ($listing !== null) {
            $request->attributes->set('contentTypeSlug', $listing);
        }
        $request->setLocale($locale);

        $config = Yaml::parseFile($this->projectDir . '/config/extensions/' . SeoRules::SEO_CONFIG_BASENAME . '.yaml');

        return new Seo(
            $twig,
            new Collection(is_array($config) ? $config : []),
            Fixtures::config([
                'contenttypes' => [
                    'projekty' => Fixtures::contentType('projekty'),
                    'clanky' => Fixtures::contentType('clanky'),
                ],
            ]),
            $request,
            self::createStub(TranslatorInterface::class),
        );
    }

    /**
     * @param array<string, array<string, string>|string> $seoByLocale locale => values, or raw text
     */
    private function record(array $seoByLocale): Content
    {
        $record = Fixtures::content(1, Fixtures::contentType('pages'));
        Fixtures::addField($record, new Field(), 'seo', array_map(
            static fn (array|string $values): array => [is_string($values) ? $values : json_encode($values, JSON_THROW_ON_ERROR)],
            $seoByLocale
        ), 'cs');

        return $record;
    }
}
