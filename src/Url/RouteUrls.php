<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

use Bolt\Configuration\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Absolute URLs of routes in a given locale, the way Bolt's canonical links are
 * built (`Canonical::generateLink()`): in the default locale, `<name>_locale` is
 * swapped for `<name>` (served without the `/{_locale}` prefix) when
 * `remove_default_locale_on_canonical` is on, which it is by default.
 *
 * Unlike `Canonical`, a route that can't be generated yields null (logged)
 * instead of the current request's URL, and parameters the route doesn't use are
 * dropped instead of ending up in a query string.
 */
final readonly class RouteUrls
{
    public function __construct(
        private RouterInterface $router,
        private Config $boltConfig,
        private SiteLocales $siteLocales,
        private SiteUrl $siteUrl,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{route: string, url: string}|null the route that was used and its URL
     */
    public function url(string $route, array $params, ?string $locale = null): ?array
    {
        $routes = $this->router->getRouteCollection();
        $locale ??= $this->siteLocales->default();

        if ($locale === $this->siteLocales->default() && $this->removeDefaultLocale()) {
            $withoutLocale = str_replace('_locale', '', $route);
            if ($withoutLocale !== $route && $routes->get($withoutLocale) !== null) {
                $route = $withoutLocale;
            }
        }

        $definition = $routes->get($route);
        if ($definition === null) {
            $this->logger->notice('Sitemap: route "{route}" does not exist.', [
                'route' => $route,
            ]);

            return null;
        }

        $variables = $definition->compile()
            ->getVariables();
        $params = array_filter(
            $params + [
                '_locale' => $locale,
            ],
            static fn (string $name): bool => in_array($name, $variables, true),
            ARRAY_FILTER_USE_KEY
        );

        try {
            $path = $this->router->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (ExceptionInterface $exception) {
            $this->logger->notice('Sitemap: could not generate a URL for route "{route}": {message}', [
                'route' => $route,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return [
            'route' => $route,
            'url' => $this->siteUrl->absolute($path),
        ];
    }

    private function removeDefaultLocale(): bool
    {
        return $this->boltConfig->get('general/localization/remove_default_locale_on_canonical', true) !== false;
    }
}
