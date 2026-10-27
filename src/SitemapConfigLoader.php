<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Bolt\Configuration\Config;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the extension config outside of the Extension object (controllers and
 * services can't use `BaseExtension::getConfig()`, which derives the filename
 * from the calling class's namespace).
 *
 * Same resolution as Bolt's `ConfigTrait`: the project's
 * `config/extensions/tomvondracek-sitemap.yaml`, overridden by `…_local.yaml`,
 * falling back to the defaults shipped with the package.
 */
final readonly class SitemapConfigLoader
{
    public const CONFIG_BASENAME = 'tomvondracek-sitemap';

    public function __construct(
        private Config $boltConfig,
    ) {
    }

    public function load(): SitemapConfig
    {
        $directory = $this->boltConfig->getPath('extensions_config');
        $main = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '.yaml';
        $local = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '_local.yaml';

        $files = is_readable($main) ? [$main, $local] : [self::defaultConfigFile(), $local];

        return SitemapConfig::fromArray(self::parseFiles($files));
    }

    /**
     * The raw config of another extension, such as bolt-seo's, from the project's
     * `config/extensions/<basename>.yaml` and `…_local.yaml`.
     *
     * @return array<array-key, mixed>
     */
    public function loadOther(string $basename): array
    {
        $directory = $this->boltConfig->getPath('extensions_config');

        return self::parseFiles([
            $directory . DIRECTORY_SEPARATOR . $basename . '.yaml',
            $directory . DIRECTORY_SEPARATOR . $basename . '_local.yaml',
        ]);
    }

    /**
     * @internal
     */
    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__) . '/config/config.yaml';
    }

    /**
     * @internal
     *
     * @param list<string> $files
     *
     * @return array<array-key, mixed>
     */
    public static function parseFiles(array $files): array
    {
        $config = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $parsed = Yaml::parseFile($file);
            if (is_array($parsed)) {
                $config = array_merge($config, $parsed);
            }
        }

        return $config;
    }
}
