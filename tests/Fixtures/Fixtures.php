<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Fixtures;

use Bolt\Collection\DeepCollection;
use Bolt\Configuration\Config;
use Bolt\Configuration\Content\ContentType;
use Bolt\Configuration\PathResolver;
use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\Field\ImageField;
use Bolt\Entity\Field\ImagelistField;
use Bolt\Entity\Field\SlugField;
use Bolt\Entity\FieldTranslation;
use DateTime;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Tomvondracek\Sitemap\Content\StaticRoutes;
use Tomvondracek\Sitemap\Url\SiteLocales;
use Tomvondracek\Sitemap\Url\SiteUrl;

/**
 * Builds real Bolt objects (no kernel, no database) for tests.
 */
final class Fixtures
{
    public const ORIGIN = 'https://example.com';
    public const LOCALES = 'cs|en|de';

    /**
     * A Bolt Config holding `$data` (paths like `general/homepage` resolve into it).
     *
     * @param array<string, mixed> $data
     */
    public static function config(array $data = [], string $projectDir = '/project'): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $contentTypes = $data['contenttypes'] ?? [];
        (new ReflectionProperty(Config::class, 'data'))->setValue($config, DeepCollection::deepMake($data)->merge([
            // Keep the ContentType objects; deepMake() would make them plain collections.
            'contenttypes' => DeepCollection::make(is_array($contentTypes) ? $contentTypes : []),
        ]));
        (new ReflectionProperty(Config::class, 'pathResolver'))->setValue($config, new PathResolver($projectDir, 'base-2021', 'public'));

        return $config;
    }

    /**
     * @param list<ContentType> $contentTypes
     * @param array<string, mixed> $general
     * @param array<string, mixed> $taxonomies
     */
    public static function boltConfig(array $contentTypes, array $general = [], array $taxonomies = []): Config
    {
        $bySlug = [];
        foreach ($contentTypes as $contentType) {
            $bySlug[$contentType->getSlug()] = $contentType;
        }

        return self::config([
            'general' => $general + [
                'homepage' => 'homepage',
            ],
            'contenttypes' => $bySlug,
            'taxonomies' => $taxonomies,
        ]);
    }

    /**
     * A ContentType with a localized `slug`, a `title`, an `image` and a `gallery`.
     *
     * @param array<string, mixed> $overrides
     */
    public static function contentType(string $slug, array $overrides = []): ContentType
    {
        $singular = is_string($overrides['singular_slug'] ?? null) ? $overrides['singular_slug'] : mb_rtrim($slug, 's');
        $locales = $overrides['locales'] ?? ['cs', 'en'];

        /** @var ContentType $contentType deepMake() returns static */
        $contentType = ContentType::deepMake(array_replace([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'singular_slug' => $singular,
            'singular_name' => ucfirst($singular),
            'locales' => $locales,
            'singleton' => false,
            'viewless' => false,
            'viewless_listing' => false,
            'record_route' => $locales === [] ? 'record' : 'record_locale',
            'fields' => [
                'title' => ['type' => 'text', 'localize' => true],
                'slug' => ['type' => 'slug', 'localize' => true],
                'image' => ['type' => 'image', 'localize' => false],
                'gallery' => ['type' => 'imagelist', 'localize' => false],
                'seo' => ['type' => 'seo', 'localize' => true],
            ],
        ], $overrides));

        return $contentType;
    }

    /**
     * A record with a slug per locale (the first is also the default).
     *
     * @param array<string, string> $slugs locale => slug
     */
    public static function content(int $id, ContentType $contentType, array $slugs = [], ?string $modifiedAt = null): Content
    {
        $content = new Content();
        $content->setId($id);
        $content->setContentType($contentType->getSlug());
        $content->setDefinition($contentType);
        $content->setStatus('published');

        if ($modifiedAt !== null) {
            $content->setModifiedAt(new DateTime($modifiedAt));
        }

        if ($slugs !== []) {
            self::addField($content, new SlugField(), 'slug', array_map(static fn (string $slug): array => [$slug], $slugs), (string) array_key_first($slugs));
        }

        return $content;
    }

    /**
     * @param array<string, array<array-key, mixed>> $valuesByLocale
     */
    public static function addField(Content $content, Field $field, string $name, array $valuesByLocale, ?string $defaultLocale = null): Field
    {
        $field->setName($name);

        if ($defaultLocale !== null) {
            $field->setDefaultLocale($defaultLocale);
        }

        foreach ($valuesByLocale as $locale => $value) {
            $translation = new FieldTranslation();
            $translation->setLocale($locale);
            $translation->setValue($value);
            $field->addTranslation($translation);
        }

        $content->addField($field);

        return $field;
    }

    public static function addImage(Content $content, string $name, string $filename): Field
    {
        return self::addField($content, new ImageField(), $name, [
            'cs' => [
                'filename' => $filename,
            ],
        ], 'cs');
    }

    /**
     * @param list<string> $filenames
     */
    public static function addImagelist(Content $content, string $name, array $filenames): Field
    {
        return self::addField($content, new ImagelistField(), $name, [
            'cs' => array_map(static fn (string $filename): array => [
                'filename' => $filename,
            ], $filenames),
        ], 'cs');
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function addSeo(Content $content, array $values, string $locale = 'cs'): Field
    {
        return self::addField($content, new Field(), 'seo', [
            $locale => [json_encode($values, JSON_THROW_ON_ERROR)],
        ], $locale);
    }

    /**
     * Bolt's frontend routes.
     */
    public static function routes(string $locales = self::LOCALES): RouteCollection
    {
        $routes = new RouteCollection();
        $locale = [
            '_locale' => $locales,
        ];
        $routes->add('homepage', new Route('/'));
        $routes->add('homepage_locale', new Route('/{_locale}/', [], $locale));
        $routes->add('taxonomy', new Route('/{taxonomyslug}/{slug}'));
        $routes->add('taxonomy_locale', new Route('/{_locale}/{taxonomyslug}/{slug}', [], $locale));
        $routes->add('listing', new Route('/{contentTypeSlug}'));
        $routes->add('listing_locale', new Route('/{_locale}/{contentTypeSlug}', [], $locale));
        $routes->add('record', new Route('/{contentTypeSlug}/{slugOrId}'));
        $routes->add('record_locale', new Route('/{_locale}/{contentTypeSlug}/{slugOrId}', [], $locale));

        return $routes;
    }

    /**
     * A template-only route, as config/routes.yaml defines them.
     *
     * @param array<string, string> $requirements
     */
    public static function templateRoute(string $path, string $template, array $requirements = []): Route
    {
        return new Route($path, [
            '_controller' => StaticRoutes::TEMPLATE_CONTROLLER,
            'templateName' => $template,
        ], $requirements);
    }

    public static function router(RouteCollection $routes): RouterInterface
    {
        return new class($routes) implements RouterInterface {
            private RequestContext $context;

            public function __construct(
                private readonly RouteCollection $routes,
            ) {
                $this->context = (new RequestContext())->setHost('internal.example.org')
                    ->setScheme('http');
            }

            public function getRouteCollection(): RouteCollection
            {
                return $this->routes;
            }

            /**
             * @return array<string, mixed>
             */
            public function match(string $pathinfo): array
            {
                return [];
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return (new UrlGenerator($this->routes, $this->context))->generate($name, $parameters, $referenceType);
            }
        };
    }

    /**
     * The site's address, as `general/canonical` sets it; null = no request.
     */
    public static function siteUrl(?string $homepage = self::ORIGIN . '/'): SiteUrl
    {
        $canonical = (new ReflectionClass(FakeCanonical::class))->newInstanceWithoutConstructor();
        $canonical->homepage = $homepage;

        $requestStack = new RequestStack();
        if ($homepage !== null) {
            $requestStack->push(Request::create('http://internal.example.org/sitemap.xml'));
        }

        return new SiteUrl($canonical, $requestStack);
    }

    /**
     * @param list<string> $locales
     */
    public static function siteLocales(string $default = 'cs', array $locales = ['cs', 'en', 'de']): SiteLocales
    {
        return new SiteLocales(self::parameters([
            'locale' => $default,
            'locales_array' => $locales,
        ]));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function parameters(array $parameters): ContainerBag
    {
        return new ContainerBag(new Container(new ParameterBag($parameters)));
    }

    public static function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
