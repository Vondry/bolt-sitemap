<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Tomvondracek\Sitemap\Extension;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExtensionTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame('XML Sitemap', (new Extension())->getName());
        self::assertSame('xml-sitemap', (new Extension())->getSlug());
    }

    /**
     * Bolt's ConfigTrait (used by `extensions:configure --with-config`) and
     * SitemapConfigLoader must agree on the config filename, or the project's
     * config file is never read.
     */
    public function testConfigFilenameMatchesTheLoader(): void
    {
        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn('/project/config/extensions');

        $container = new Container();
        $container->set(Config::class, $config);

        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);

        $filenames = $extension->getConfigFilenames();
        self::assertIsString($filenames['main'] ?? null);
        self::assertIsString($filenames['local'] ?? null);

        self::assertSame(SitemapConfigLoader::CONFIG_BASENAME . '.yaml', basename($filenames['main']));
        self::assertSame(SitemapConfigLoader::CONFIG_BASENAME . '_local.yaml', basename($filenames['local']));
    }

    public function testInitializeRegistersTwigNamespace(): void
    {
        $loader = new FilesystemLoader();
        $container = new Container();
        $container->set('twig', new Environment($loader));

        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);
        $extension->initialize();

        self::assertContains(dirname(__DIR__) . '/templates', $loader->getPaths('bolt-sitemap'));
    }
}
