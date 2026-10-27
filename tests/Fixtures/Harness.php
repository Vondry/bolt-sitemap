<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Fixtures;

use Bolt\Configuration\Config;
use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Sitemap\Content\Homepage;
use Tomvondracek\Sitemap\Content\Images;
use Tomvondracek\Sitemap\Content\RecordRoutes;
use Tomvondracek\Sitemap\Content\SeoRules;
use Tomvondracek\Sitemap\Content\StaticRoutes;
use Tomvondracek\Sitemap\Controller\SitemapController;
use Tomvondracek\Sitemap\Segment\SegmentRegistry;
use Tomvondracek\Sitemap\Segment\SegmentUrls;
use Tomvondracek\Sitemap\SitemapCache;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\SitemapGenerator;
use Tomvondracek\Sitemap\State\StateStore;
use Tomvondracek\Sitemap\TemplateRenderer;
use Tomvondracek\Sitemap\Url\RouteUrls;
use Tomvondracek\Sitemap\Url\SiteLocales;
use Tomvondracek\Sitemap\Url\SitemapUrl;
use Tomvondracek\Sitemap\Url\SiteUrl;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The sitemap services wired together over in-memory content, Bolt's routes and
 * a temporary project directory (for the state file and other extensions'
 * config).
 */
final class Harness
{
    public readonly string $projectDir;
    public readonly Config $boltConfig;
    public readonly InMemoryContentSource $source;
    public readonly RouteCollection $routes;
    public readonly RouterInterface $router;
    public readonly SiteUrl $siteUrl;
    public readonly SiteLocales $siteLocales;
    public readonly EventDispatcher $dispatcher;
    public readonly MockClock $clock;
    public readonly SitemapConfigLoader $configLoader;
    public readonly SegmentRegistry $registry;
    public readonly SegmentUrls $segmentUrls;
    public readonly FilesystemLoader $twigLoader;
    public readonly SitemapCache $cache;
    public SitemapConfig $config;

    /**
     * @param list<ContentType> $contentTypes
     * @param list<Content> $records
     * @param array<string, mixed> $config the extension's config
     * @param array<string, mixed> $general config/bolt/config.yaml
     * @param array<string, mixed> $taxonomies config/bolt/taxonomy.yaml
     * @param array<string, list<string>> $taxonomySlugs
     * @param array<string, mixed>|null $seoConfig bolt-seo's config; null = not installed
     */
    public function __construct(
        array $contentTypes,
        array $records = [],
        array $config = [],
        array $general = [],
        ?RouteCollection $routes = null,
        array $taxonomies = [],
        array $taxonomySlugs = [],
        ?array $seoConfig = null,
        ?SiteUrl $siteUrl = null,
    ) {
        $this->projectDir = sys_get_temp_dir() . '/bolt-sitemap-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/config/extensions', 0o777, true);
        if ($seoConfig !== null) {
            file_put_contents($this->projectDir . '/config/extensions/' . SeoRules::SEO_CONFIG_BASENAME . '.yaml', Yaml::dump($seoConfig, 6));
        }

        $bySlug = [];
        foreach ($contentTypes as $contentType) {
            $bySlug[$contentType->getSlug()] = $contentType;
        }

        $this->boltConfig = Fixtures::config([
            'general' => $general + [
                'homepage' => null,
            ],
            'contenttypes' => $bySlug,
            'taxonomies' => $taxonomies,
        ], $this->projectDir);

        $this->source = new InMemoryContentSource($records, $taxonomySlugs);
        $this->routes = $routes ?? Fixtures::routes();
        $this->routes->add(SitemapController::ROUTE_INDEX, new Route('/sitemap.xml'));
        $this->routes->add(SitemapController::ROUTE_CHILD, new Route('/sitemap-{name}.xml'));
        $this->routes->add(SitemapController::ROUTE_STYLESHEET, new Route('/sitemap.css'));
        $this->routes->add(SitemapController::ROUTE_SCRIPT, new Route('/sitemap-ui.js'));
        $this->router = Fixtures::router($this->routes);
        $this->siteUrl = $siteUrl ?? Fixtures::siteUrl();
        $this->siteLocales = Fixtures::siteLocales();
        $this->dispatcher = new EventDispatcher();
        $this->clock = new MockClock('2026-10-27 12:00:00 UTC');
        $this->configLoader = new SitemapConfigLoader($this->boltConfig);
        $this->config = SitemapConfig::fromArray($config);

        $homepage = new Homepage($this->boltConfig);
        $this->registry = new SegmentRegistry($this->boltConfig, $this->source, $homepage, $this->siteLocales);
        $this->segmentUrls = new SegmentUrls(
            $this->registry,
            $this->source,
            $homepage,
            new RecordRoutes($homepage),
            new RouteUrls($this->router, $this->boltConfig, $this->siteLocales, $this->siteUrl, new NullLogger()),
            $this->siteLocales,
            new StaticRoutes($this->router, $this->siteUrl),
            new Images($this->siteUrl),
            new SeoRules($this->configLoader, $this->siteUrl, $seoConfig !== null),
            $this->stateStore(),
            $this->clock,
            $this->dispatcher,
        );

        $this->twigLoader = new FilesystemLoader();
        $this->twigLoader->addPath(dirname(__DIR__, 2) . '/templates', TemplateRenderer::NAMESPACE);
        $this->cache = new SitemapCache(new TagAwareAdapter(new ArrayAdapter()));
    }

    public function __destruct()
    {
        (new Filesystem())->remove($this->projectDir);
    }

    /**
     * The URLs of /sitemap-<name>.xml.
     *
     * @return list<SitemapUrl>
     */
    public function urls(string $name): array
    {
        $resolved = $this->registry->resolve($this->config, $name);
        if ($resolved === null) {
            return [];
        }

        return $this->segmentUrls->urls($this->config, $resolved[0], $resolved[1]);
    }

    /**
     * The `<loc>`s of /sitemap-<name>.xml.
     *
     * @return list<string>
     */
    public function locs(string $name): array
    {
        return array_map(static fn (SitemapUrl $url): string => $url->loc, $this->urls($name));
    }

    public function url(string $name, string $loc): ?SitemapUrl
    {
        foreach ($this->urls($name) as $url) {
            if ($url->loc === $loc) {
                return $url;
            }
        }

        return null;
    }

    public function stateStore(): StateStore
    {
        return new StateStore($this->projectDir . '/var/data/tomvondracek-sitemap.json');
    }

    public function renderer(): TemplateRenderer
    {
        return new TemplateRenderer(new Environment($this->twigLoader, [
            'strict_variables' => true,
        ]), $this->boltConfig);
    }

    public function generator(): SitemapGenerator
    {
        return new SitemapGenerator(
            $this->registry,
            $this->segmentUrls,
            $this->renderer(),
            $this->stateStore(),
            $this->cache,
            $this->siteUrl,
            $this->router,
            $this->clock,
        );
    }
}
