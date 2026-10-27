<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Tomvondracek\Sitemap\Url\SiteUrl;

/**
 * Static (template-only) pages: routes handled by Bolt's TemplateController,
 * typically defined in config/routes.yaml, which no ContentType knows about.
 *
 * Every such route that can be generated without parameters is a page. A route
 * of the same template whose only variable is `{_locale}` (the `<name>_locale`
 * twin) is not a page of its own but provides the page's other languages; the
 * default locale keeps the unprefixed URL, so it is never listed twice.
 *
 * The `{_locale}` requirement is expected to be a plain `|`-separated list
 * (`cs|en|de`), as produced by `%app_locales%`.
 *
 * Ported from bolt/sitemap-extension#4.
 */
final readonly class StaticRoutes
{
    public const TEMPLATE_CONTROLLER = 'Bolt\Controller\Frontend\TemplateController::template';

    public function __construct(
        private RouterInterface $router,
        private SiteUrl $siteUrl,
        private string $templateController = self::TEMPLATE_CONTROLLER,
    ) {
    }

    /**
     * @param list<string> $exclude route names to leave out
     *
     * @return list<array{name: string, urls: array<string, array{route: string, url: string}>}> the
     *         pages, each with its URL by locale
     */
    public function collect(string $defaultLocale, array $exclude = []): array
    {
        $routes = $this->router->getRouteCollection()
            ->all();
        $localeVariants = $this->localeVariants($routes);

        $pages = [];
        foreach ($routes as $name => $route) {
            // getVariables() covers path and host variables, so a route with a host
            // placeholder is skipped rather than failing to generate.
            if (! $this->isTemplateRoute($route) || $route->compile()->getVariables() !== [] || in_array($name, $exclude, true)) {
                continue;
            }

            $url = $this->url($name, []);
            if ($url === null) {
                continue;
            }

            $urls = [
                $defaultLocale => [
                    'route' => $name,
                    'url' => $url,
                ],
            ];

            $template = $route->getDefault('templateName');
            foreach (is_string($template) ? $localeVariants[$template] ?? [] : [] as $variant => $locales) {
                foreach ($locales as $locale) {
                    $url = isset($urls[$locale]) ? null : $this->url($variant, [
                        '_locale' => $locale,
                    ]);
                    if ($url !== null) {
                        $urls[$locale] = [
                            'route' => $variant,
                            'url' => $url,
                        ];
                    }
                }
            }

            $pages[] = [
                'name' => $name,
                'urls' => $urls,
            ];
        }

        return $pages;
    }

    /**
     * The `{_locale}`-only routes by template, with the locales each allows.
     *
     * @param array<string, Route> $routes
     *
     * @return array<string, array<string, list<string>>> template => [route name => locales]
     */
    private function localeVariants(array $routes): array
    {
        $variants = [];
        foreach ($routes as $name => $route) {
            $template = $route->getDefault('templateName');
            if (! $this->isTemplateRoute($route) || ! is_string($template) || $route->compile()->getVariables() !== ['_locale']) {
                continue;
            }

            $locales = array_values(array_filter(
                array_map(trim(...), explode('|', (string) $route->getRequirement('_locale'))),
                static fn (string $locale): bool => $locale !== ''
            ));
            $variants[$template][$name] = $locales;
        }

        return $variants;
    }

    /**
     * The URL, or null when the route can't be generated (a requirement the
     * locale doesn't match, say): one odd route must not take down the sitemap.
     *
     * @param array<string, string> $params
     */
    private function url(string $route, array $params): ?string
    {
        try {
            return $this->siteUrl->absolute($this->router->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_PATH));
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function isTemplateRoute(Route $route): bool
    {
        return $route->getDefault('_controller') === $this->templateController;
    }
}
