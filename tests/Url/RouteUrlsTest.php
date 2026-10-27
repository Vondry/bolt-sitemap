<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Url;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Tomvondracek\Sitemap\Tests\Fixtures\MemoryLogger;
use Tomvondracek\Sitemap\Url\RouteUrls;

final class RouteUrlsTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
    }

    public function testDefaultLocaleUsesTheRouteWithoutPrefix(): void
    {
        self::assertSame([
            'route' => 'record',
            'url' => 'https://example.com/page/about',
        ], $this->routeUrls()->url('record_locale', [
            'contentTypeSlug' => 'page',
            'slugOrId' => 'about',
        ], 'cs'));
    }

    public function testOtherLocaleKeepsThePrefix(): void
    {
        self::assertSame([
            'route' => 'record_locale',
            'url' => 'https://example.com/en/page/about',
        ], $this->routeUrls()->url('record_locale', [
            'contentTypeSlug' => 'page',
            'slugOrId' => 'about',
        ], 'en'));
    }

    public function testNoLocaleMeansTheDefault(): void
    {
        self::assertSame('https://example.com/', $this->routeUrls()->url('homepage_locale', [])['url'] ?? null);
    }

    public function testDefaultLocaleKeepsThePrefixWhenConfiguredSo(): void
    {
        $routeUrls = $this->routeUrls([
            'general' => [
                'localization' => [
                    'remove_default_locale_on_canonical' => false,
                ],
            ],
        ]);

        self::assertSame('https://example.com/cs/', $routeUrls->url('homepage_locale', [], 'cs')['url'] ?? null);
    }

    /**
     * A route with fixed defaults (akvelmez: `privacy_locale` with `slugOrId`)
     * must not get `?slugOrId=…` appended.
     */
    public function testParametersTheRouteDoesntUseAreDropped(): void
    {
        $routes = Fixtures::routes();
        $routes->add('privacy', new Route('/zasady-ochrany-osobnich-udaju', [
            'contentTypeSlug' => 'privacy',
            'slugOrId' => 'privacy',
        ]));
        $routes->add('privacy_locale', new Route('/{_locale}/zasady-ochrany-osobnich-udaju', [
            'contentTypeSlug' => 'privacy',
            'slugOrId' => 'privacy',
        ], [
            '_locale' => 'cs|en',
        ]));

        $routeUrls = new RouteUrls(Fixtures::router($routes), Fixtures::config(), Fixtures::siteLocales(), Fixtures::siteUrl(), $this->logger);
        $params = [
            'contentTypeSlug' => 'privacy',
            'slugOrId' => 'zasady-2',
        ];

        self::assertSame('https://example.com/zasady-ochrany-osobnich-udaju', $routeUrls->url('privacy_locale', $params, 'cs')['url'] ?? null);
        self::assertSame('https://example.com/en/zasady-ochrany-osobnich-udaju', $routeUrls->url('privacy_locale', $params, 'en')['url'] ?? null);
    }

    public function testUnknownRouteIsNullAndLogged(): void
    {
        self::assertNull($this->routeUrls()->url('missing', []));
        self::assertSame(['Sitemap: route "{route}" does not exist.'], $this->logger->messages);
    }

    public function testLocaleTheRouteDoesntAllowIsNullAndLogged(): void
    {
        self::assertNull($this->routeUrls()->url('homepage_locale', [], 'fr'));
        self::assertCount(1, $this->logger->messages);
        self::assertStringStartsWith('Sitemap: could not generate a URL', $this->logger->messages[0]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function routeUrls(array $config = []): RouteUrls
    {
        return new RouteUrls(Fixtures::router(Fixtures::routes()), Fixtures::config($config), Fixtures::siteLocales(), Fixtures::siteUrl(), $this->logger);
    }
}
