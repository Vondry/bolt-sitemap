<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class SitemapConfigLoaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/bolt-sitemap-loader-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/config/extensions', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testShippedDefaultsWithoutAProjectFile(): void
    {
        self::assertSame(10000, $this->loader()->load()->chunkSize);
    }

    public function testProjectFileOverriddenByLocalFile(): void
    {
        $this->write('tomvondracek-sitemap.yaml', "chunk_size: 500\nmax_age: 60\n");
        $this->write('tomvondracek-sitemap_local.yaml', "max_age: 0\n");

        $config = $this->loader()->load();

        self::assertSame(500, $config->chunkSize);
        self::assertSame(0, $config->maxAge);
    }

    public function testLocalFileAppliesOverTheShippedDefaults(): void
    {
        $this->write('tomvondracek-sitemap_local.yaml', "max_age: 0\n");

        self::assertSame(0, $this->loader()->load()->maxAge);
    }

    public function testEmptyProjectFileMeansDefaults(): void
    {
        $this->write('tomvondracek-sitemap.yaml', "# nothing\n");

        self::assertSame(3600, $this->loader()->load()->maxAge);
    }

    public function testLoadsAnotherExtensionsConfig(): void
    {
        $this->write('appolo-boltseo.yaml', "default:\n  robots: 'index, follow'\n");
        $this->write('appolo-boltseo_local.yaml', "default:\n  robots: noindex\n");

        self::assertSame([
            'default' => [
                'robots' => 'noindex',
            ],
        ], $this->loader()->loadOther('appolo-boltseo'));
        self::assertSame([], $this->loader()->loadOther('missing'));
    }

    private function loader(): SitemapConfigLoader
    {
        return new SitemapConfigLoader(Fixtures::config([], $this->projectDir));
    }

    private function write(string $file, string $yaml): void
    {
        file_put_contents($this->projectDir . '/config/extensions/' . $file, $yaml);
    }
}
