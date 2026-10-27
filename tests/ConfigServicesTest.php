<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\ServiceValueResolver;
use Symfony\Component\HttpKernel\DependencyInjection\RegisterControllerArgumentLocatorsPass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tomvondracek\Sitemap\Content\DoctrineContentSource;
use Tomvondracek\Sitemap\Controller\SitemapController;
use Tomvondracek\Sitemap\EventListener\ContentChangeListener;
use Tomvondracek\Sitemap\Segment\SegmentRegistry;
use Tomvondracek\Sitemap\Segment\SegmentUrls;
use Tomvondracek\Sitemap\SitemapCache;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\SitemapGenerator;
use Tomvondracek\Sitemap\State\StateStore;
use Tomvondracek\Sitemap\TemplateRenderer;
use Twig\Environment;

/**
 * Guards the registrations: what Bolt's generated config/services_bolt.yaml does
 * with the extension's classes, and config/services.yaml and config/routes.yaml,
 * which `extensions:configure` copies into the project.
 */
final class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-sitemap';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bolt-sitemap-services-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testTwigNamespaceIsRegisteredForTheShippedTemplates(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml');
        self::assertIsArray($config);

        self::assertSame([
            'paths' => [
                self::VENDOR_PATH . '/templates' => TemplateRenderer::NAMESPACE,
            ],
        ], $config['twig'] ?? null);

        foreach (SitemapConfig::DEFAULT_TEMPLATES as $template) {
            self::assertFileExists(dirname(__DIR__) . '/templates/' . $template);
        }
    }

    public function testRoutesLoadTheControllerDirectory(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__) . '/config/routes.yaml');
        self::assertIsArray($routes);

        self::assertSame(
            [
                'resource' => '../../vendor/tomvondracek/bolt-sitemap/src/Controller/',
                'type' => 'attribute',
            ],
            $routes['tomvondracek_sitemap'] ?? null
        );
    }

    /**
     * Right after `composer require`, Bolt has added the extension to
     * config/services_bolt.yaml, but `extensions:configure` (which needs a working
     * container itself) has not copied config/services.yaml yet.
     */
    public function testContainerCompilesBeforeExtensionsConfigure(): void
    {
        $this->assertServicesAreAutowired($this->compile(false));
    }

    public function testContainerCompilesAfterExtensionsConfigure(): void
    {
        $this->assertServicesAreAutowired($this->compile(true));
    }

    public function testContentChangeListenerListensToDoctrineFlushes(): void
    {
        $definition = $this->compile(true)->getDefinition(ContentChangeListener::class);

        self::assertSame([[
            'event' => 'onFlush',
        ], [
            'event' => 'postFlush',
        ]], $definition->getTag('doctrine.event_listener'));
        $arguments = $definition->getArguments();
        self::assertCount(1, $arguments);
        self::assertInstanceOf(Reference::class, $arguments[0]);
        self::assertSame(SitemapCache::class, (string) $arguments[0]);
    }

    public function testStateFileIsInTheProjectsDataDirectory(): void
    {
        $arguments = $this->compile(true)->getDefinition(StateStore::class)->getArguments();

        self::assertSame([dirname(__DIR__) . '/var/data/tomvondracek-sitemap.json'], $arguments);
    }

    private function compile(bool $configured): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', dirname(__DIR__));
        // Bolt's parameters, not bound for extension classes.
        $container->setParameter('locale', 'cs');
        $container->setParameter('locales_array', ['cs', 'en']);

        // Services of FrameworkBundle, SecurityBundle, TwigBundle, DoctrineBundle and Bolt.
        foreach ([ContainerInterface::class, Config::class, Environment::class, Canonical::class, RequestStack::class, TokenStorageInterface::class, ContainerBagInterface::class, ClockInterface::class, TagAwareCacheInterface::class, RouterInterface::class, UrlGeneratorInterface::class, EntityManagerInterface::class, EventDispatcherInterface::class, LoggerInterface::class] as $service) {
            $container->register($service)
                ->setSynthetic(true);
        }
        $container->registerForAutoconfiguration(AbstractController::class)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber');
        $container->register('argument_resolver.service', ServiceValueResolver::class)
            ->addArgument(null);
        $container->addCompilerPass(new RegisterControllerArgumentLocatorsPass());

        // What Bolt's ExtensionCompilerPass writes to config/services_bolt.yaml.
        $src = dirname(__DIR__) . '/src';
        file_put_contents($this->dir . '/services_bolt.yaml', Yaml::dump([
            'services' => [
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                    'bind' => [],
                ],
                'Tomvondracek\Sitemap\\' => [
                    'resource' => $src . '/*',
                    'exclude' => $src . '/{Entity,Exception}',
                ],
            ],
        ], 4, 2));
        (new YamlFileLoader($container, new FileLocator($this->dir)))->load('services_bolt.yaml');

        if ($configured) {
            // config/services.yaml also configures Twig, which isn't part of this container.
            $container->registerExtension(new class() extends Extension {
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return 'twig';
                }
            });
            (new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . '/config')))->load('services.yaml');
        }

        // Controllers are public in a project, so errors in them surface when compiling;
        // DoctrineBundle makes its listeners available the same way.
        foreach ([SitemapController::class, ContentChangeListener::class, StateStore::class, SegmentUrls::class] as $public) {
            $container->getDefinition($public)
                ->setPublic(true);
        }
        $container->compile(true);

        return $container;
    }

    private function assertServicesAreAutowired(ContainerBuilder $container): void
    {
        self::assertSame(
            [Config::class, SitemapConfigLoader::class, SitemapGenerator::class, SegmentRegistry::class, TemplateRenderer::class, TokenStorageInterface::class, ContainerBagInterface::class],
            $this->argumentClasses($container->getDefinition(SitemapController::class))
        );
        self::assertContains(DoctrineContentSource::class, $this->argumentClasses($container->getDefinition(SegmentUrls::class)), 'the ContentSource interface resolves to Doctrine');
    }

    /**
     * @return list<string|null>
     */
    private function argumentClasses(Definition $definition): array
    {
        // Services used once are inlined as their definition.
        return array_values(array_map(static fn (mixed $argument): ?string => match (true) {
            $argument instanceof Reference => (string) $argument,
            $argument instanceof Definition => $argument->getClass(),
            default => get_debug_type($argument),
        }, $definition->getArguments()));
    }
}
