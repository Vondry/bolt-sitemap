<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Controller;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Sitemap\Controller\SitemapController;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;
use Tomvondracek\Sitemap\Tests\Fixtures\Harness;

final class SitemapControllerTest extends TestCase
{
    private Harness $harness;
    private TokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $clanky = Fixtures::contentType('clanky', [
            'singular_slug' => 'clanek',
            'locales' => [],
        ]);
        $this->harness = new Harness([$clanky], [
            Fixtures::content(1, $clanky, [
                'cs' => 'prvni',
            ], '2026-10-24 06:30:00'),
            Fixtures::content(2, $clanky, [
                'cs' => 'druhy',
            ], '2026-10-20 06:30:00'),
        ]);
        $this->tokenStorage = new TokenStorage();
    }

    public function testRoutes(): void
    {
        self::assertSame(['/sitemap.xml', SitemapController::ROUTE_INDEX], $this->route('index'));
        self::assertSame(['/sitemap-{name}.xml', SitemapController::ROUTE_CHILD], $this->route('child'));
        self::assertSame(['/sitemap.css', SitemapController::ROUTE_STYLESHEET], $this->route('stylesheet'));
        self::assertSame(['/sitemap-ui.js', SitemapController::ROUTE_SCRIPT], $this->route('script'));
    }

    public function testIndex(): void
    {
        $request = Request::create('/sitemap.xml');
        $response = $this->controller()->index($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('<loc>https://example.com/sitemap-clanky.xml</loc>', (string) $response->getContent());
        self::assertTrue($request->attributes->get(SitemapController::REQUEST_ATTRIBUTE));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testChild(): void
    {
        $response = $this->controller()->child(Request::create('/sitemap-clanky.xml'), 'clanky');

        self::assertStringContainsString('<loc>https://example.com/clanek/prvni</loc>', (string) $response->getContent());
    }

    public function testUnknownChild(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->child(Request::create('/sitemap-nope.xml'), 'nope');
    }

    public function testEmptyChildIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        // `/` is not listed while the homepage setting names no ContentType.
        $this->harness = new Harness([], general: [
            'homepage' => 'missing',
        ]);
        $this->controller()->child(Request::create('/sitemap-main.xml'), 'main');
    }

    public function testLoggedInUserGetsAFreshPrivateCopy(): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null), 'main'));
        $controller = $this->controller();
        $controller->child(Request::create('/sitemap-clanky.xml'), 'clanky');

        $this->harness->source->remove(1);
        $this->harness->registry->reset();
        $response = $controller->child(Request::create('/sitemap-clanky.xml'), 'clanky');

        self::assertStringNotContainsString('clanek/prvni', (string) $response->getContent(), 'not from the cache');
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testAssetsAreNotFoundUnlessEnabled(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->stylesheet(Request::create('/sitemap.css'));
    }

    public function testBundledAssets(): void
    {
        file_put_contents($this->harness->projectDir . '/config/extensions/tomvondracek-sitemap.yaml', Yaml::dump([
            'human_view' => [
                'stylesheet' => true,
                'script' => true,
            ],
        ]));

        $css = $this->controller()->stylesheet(Request::create('/sitemap.css'));
        self::assertSame('text/css; charset=UTF-8', $css->headers->get('Content-Type'));
        self::assertStringStartsWith('@namespace sm url(http://www.sitemaps.org/schemas/sitemap/0.9);', (string) $css->getContent());

        $js = $this->controller()->script(Request::create('/sitemap-ui.js'));
        self::assertSame('text/javascript; charset=UTF-8', $js->headers->get('Content-Type'));
        self::assertStringStartsWith('(() => {', (string) $js->getContent());
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', run);", (string) $js->getContent());
        self::assertStringNotContainsString('innerHTML', (string) $js->getContent());
        self::assertNotNull($js->getEtag());

        self::assertStringContainsString('<?xml-stylesheet type="text/css" href="/sitemap.css"?>', (string) $this->controller()->index(Request::create('/sitemap.xml'))->getContent());
    }

    private function controller(): SitemapController
    {
        return new SitemapController(
            $this->harness->boltConfig,
            $this->harness->configLoader,
            $this->harness->generator(),
            $this->harness->registry,
            $this->harness->renderer(),
            $this->tokenStorage,
            Fixtures::parameters([
                'kernel.debug' => false,
            ]),
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function route(string $method): array
    {
        $attributes = (new ReflectionMethod(SitemapController::class, $method))->getAttributes(Route::class);
        self::assertCount(1, $attributes);
        $route = $attributes[0]->newInstance();
        self::assertSame([Request::METHOD_GET, Request::METHOD_HEAD], $route->methods);
        self::assertIsString($route->path);

        return [$route->path, $route->name];
    }
}
