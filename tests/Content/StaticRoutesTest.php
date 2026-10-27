<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tomvondracek\Sitemap\Content\StaticRoutes;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

/**
 * Ported from bolt/sitemap-extension#4 (StaticRouteCollectorTest).
 */
final class StaticRoutesTest extends TestCase
{
    public function testCollectsATemplateRouteOnTheCanonicalHost(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', Fixtures::templateRoute('/o-nas', 'pages/about.twig'));

        self::assertSame([[
            'name' => 'about',
            'urls' => [
                'cs' => [
                    'route' => 'about',
                    'url' => 'https://example.com/o-nas',
                ],
            ],
        ]], $this->collect($routes));
    }

    public function testSkipsRoutesWithADifferentController(): void
    {
        $routes = new RouteCollection();
        $routes->add('blog', new Route('/blog', [
            '_controller' => 'App\Controller\BlogController::index',
        ]));
        // Legacy redirects (akvelmez: /gdpr → /zasady-ochrany-osobnich-udaju).
        $routes->add('gdpr', new Route('/gdpr', [
            '_controller' => RedirectController::class,
            'path' => '/zasady-ochrany-osobnich-udaju',
        ]));

        self::assertSame([], $this->collect($routes));
    }

    public function testSkipsRoutesThatRequireParameters(): void
    {
        $routes = new RouteCollection();
        $routes->add('article', Fixtures::templateRoute('/article/{slug}', 'pages/article.twig'));

        self::assertSame([], $this->collect($routes));
    }

    /**
     * A host placeholder is not a path variable, so it must still be skipped:
     * generating the URL without the host argument would throw and take down the
     * whole sitemap.
     */
    public function testSkipsRoutesWithAHostVariableWithoutThrowing(): void
    {
        $routes = new RouteCollection();
        $hostRoute = Fixtures::templateRoute('/landing', 'pages/landing.twig');
        $hostRoute->setHost('{sub}.example.com');
        $routes->add('landing', $hostRoute);
        $routes->add('about', Fixtures::templateRoute('/about', 'pages/about.twig'));

        self::assertSame(['about'], array_column($this->collect($routes), 'name'));
    }

    public function testExcludesRoutesListedByName(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', Fixtures::templateRoute('/about', 'pages/about.twig'));
        $routes->add('contact', Fixtures::templateRoute('/contact', 'pages/contact.twig'));

        self::assertSame(['about'], array_column($this->collect($routes, ['contact']), 'name'));
    }

    /**
     * eurodeck: `eurodeck_okna_a_dvere` + `eurodeck_okna_a_dvere_locale` with
     * `_locale: '%app_locales%'`; the default locale keeps the unprefixed URL.
     */
    public function testLocaleVariantsProvideTheOtherLanguages(): void
    {
        $routes = new RouteCollection();
        $routes->add('okna', Fixtures::templateRoute('/okna-a-dvere', 'pages-static/okna-a-dvere.twig'));
        $routes->add('okna_locale', Fixtures::templateRoute('/{_locale}/okna-a-dvere', 'pages-static/okna-a-dvere.twig', [
            '_locale' => 'cs|en|de',
        ]));

        self::assertSame([[
            'name' => 'okna',
            'urls' => [
                'cs' => [
                    'route' => 'okna',
                    'url' => 'https://example.com/okna-a-dvere',
                ],
                'en' => [
                    'route' => 'okna_locale',
                    'url' => 'https://example.com/en/okna-a-dvere',
                ],
                'de' => [
                    'route' => 'okna_locale',
                    'url' => 'https://example.com/de/okna-a-dvere',
                ],
            ],
        ]], $this->collect($routes));
    }

    /**
     * Common setup: the default locale is served unprefixed and the `{_locale}`
     * requirement lists only the others.
     */
    public function testDefaultLocaleIsIncludedWhenTheRequirementOmitsIt(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', Fixtures::templateRoute('/about', 'pages/about.twig'));
        $routes->add('about_locale', Fixtures::templateRoute('/{_locale}/about', 'pages/about.twig', [
            '_locale' => 'de|fr',
        ]));

        self::assertSame(['cs', 'de', 'fr'], array_keys($this->collect($routes)[0]['urls']));
    }

    public function testLocaleThatCantBeGeneratedIsSkipped(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', Fixtures::templateRoute('/about', 'pages/about.twig'));
        // Bolt splits %app_locales% without trimming: `cs| en` requires " en".
        $routes->add('about_locale', Fixtures::templateRoute('/{_locale}/about', 'pages/about.twig', [
            '_locale' => 'cs| en',
        ]));

        self::assertSame(['cs'], array_keys($this->collect($routes)[0]['urls']));
    }

    public function testALocaleVariantIsNotAPageOfItsOwn(): void
    {
        $routes = new RouteCollection();
        $routes->add('about_locale', Fixtures::templateRoute('/{_locale}/about', 'pages/about.twig', [
            '_locale' => 'en|de',
        ]));

        self::assertSame([], $this->collect($routes));
    }

    public function testVariantsOfAnotherTemplateDontCount(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', Fixtures::templateRoute('/about', 'pages/about.twig'));
        $routes->add('contact_locale', Fixtures::templateRoute('/{_locale}/contact', 'pages/contact.twig', [
            '_locale' => 'en',
        ]));

        self::assertSame(['cs'], array_keys($this->collect($routes)[0]['urls']));
    }

    public function testRespectsACustomTemplateController(): void
    {
        $routes = new RouteCollection();
        $routes->add('about', new Route('/about', [
            '_controller' => 'App\Controller\CustomController::render',
            'templateName' => 'pages/about.twig',
        ]));

        $staticRoutes = new StaticRoutes(Fixtures::router($routes), Fixtures::siteUrl(), 'App\Controller\CustomController::render');

        self::assertSame(['about'], array_column($staticRoutes->collect('cs'), 'name'));
    }

    /**
     * @param list<string> $exclude
     *
     * @return list<array{name: string, urls: array<string, array{route: string, url: string}>}>
     */
    private function collect(RouteCollection $routes, array $exclude = []): array
    {
        return (new StaticRoutes(Fixtures::router($routes), Fixtures::siteUrl()))->collect('cs', $exclude);
    }
}
