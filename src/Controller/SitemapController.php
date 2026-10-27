<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Controller;

use Bolt\Configuration\Config;
use Bolt\Extension\ExtensionController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Tomvondracek\Sitemap\Segment\SegmentRegistry;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\SitemapGenerator;
use Tomvondracek\Sitemap\SitemapResponse;
use Tomvondracek\Sitemap\TemplateRenderer;

/**
 * Serves the sitemap index at /sitemap.xml, its child sitemaps at
 * /sitemap-<name>.xml and, when enabled, the stylesheet and script that show a
 * sitemap to people opening it in a browser.
 *
 * Every URL returns the same XML whatever the `Accept` header or user agent:
 * there is no content negotiation.
 *
 * Every dependency is a service, so Bolt's generated config/services_bolt.yaml
 * (autowiring without the project's binds) registers the controller as it is.
 * Not in Bolt's frontend zone, so Bolt's widgets don't inject HTML into the XML.
 */
final class SitemapController extends ExtensionController
{
    public const ROUTE_INDEX = 'tomvondracek_sitemap_index';
    public const ROUTE_CHILD = 'tomvondracek_sitemap_child';
    public const ROUTE_STYLESHEET = 'tomvondracek_sitemap_stylesheet';
    public const ROUTE_SCRIPT = 'tomvondracek_sitemap_script';

    /**
     * Request attribute that marks a request as served here, for
     * {@see \Tomvondracek\Sitemap\EventSubscriber\SitemapResponseSubscriber}.
     */
    public const REQUEST_ATTRIBUTE = '_tomvondracek_sitemap';

    public function __construct(
        Config $config,
        private readonly SitemapConfigLoader $configLoader,
        private readonly SitemapGenerator $generator,
        private readonly SegmentRegistry $registry,
        private readonly TemplateRenderer $renderer,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ContainerBagInterface $parameters,
    ) {
        parent::__construct($config);
    }

    #[Route('/sitemap.xml', name: self::ROUTE_INDEX, methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function index(Request $request): Response
    {
        $config = $this->configLoader->load();
        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        return SitemapResponse::create(
            $this->generator->index($config, $this->useCache()),
            SitemapResponse::XML,
            $config->maxAge,
            $request,
            $this->shared()
        );
    }

    #[Route('/sitemap-{name}.xml', name: self::ROUTE_CHILD, requirements: [
        'name' => '[A-Za-z0-9][A-Za-z0-9_-]*',
    ], methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function child(Request $request, string $name): Response
    {
        $config = $this->configLoader->load();

        $resolved = $this->registry->resolve($config, $name);
        $child = $resolved === null ? null : $this->generator->child($config, $resolved[0], $resolved[1], $this->useCache());
        if ($child === null || $child['count'] === 0) {
            throw $this->createNotFoundException(sprintf('There is no sitemap "%s".', $name));
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        return SitemapResponse::create($child['body'], SitemapResponse::XML, $config->maxAge, $request, $this->shared());
    }

    #[Route('/sitemap.css', name: self::ROUTE_STYLESHEET, methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function stylesheet(Request $request): Response
    {
        return $this->asset($request, 'stylesheet', SitemapResponse::CSS);
    }

    #[Route('/sitemap-ui.js', name: self::ROUTE_SCRIPT, methods: [Request::METHOD_GET, Request::METHOD_HEAD])]
    public function script(Request $request): Response
    {
        return $this->asset($request, 'script', SitemapResponse::JAVASCRIPT);
    }

    /**
     * The stylesheet or script of the human view, served only while it is enabled
     * as the bundled one (`true`); a project's own file is served by the web server.
     *
     * @param 'stylesheet'|'script' $asset
     */
    private function asset(Request $request, string $asset, string $contentType): Response
    {
        $config = $this->configLoader->load();
        $enabled = $asset === 'stylesheet' ? $config->stylesheet : $config->script;
        if ($enabled !== true) {
            throw $this->createNotFoundException(sprintf('The sitemap %s is not enabled.', $asset));
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);
        $body = $this->renderer->render($config, $asset, [
            'config' => $config,
        ]);

        return SitemapResponse::create($body, $contentType, $config->maxAge, $request);
    }

    /**
     * Logged-in users (editors checking their changes) and debug mode (template
     * changes) always get a fresh sitemap.
     */
    private function useCache(): bool
    {
        return $this->shared() && $this->parameters->get('kernel.debug') !== true;
    }

    /**
     * The sitemap must not depend on who is viewing; should an overridden template
     * do so anyway, a logged-in user's copy must not end up in a shared cache.
     */
    private function shared(): bool
    {
        return $this->tokenStorage->getToken()?->getUser() === null;
    }
}
