<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Bolt\Configuration\Config;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the extension's templates, looked up in the theme first, the way Bolt's
 * TwigAwareController::renderTemplate() does. A template of the default name the
 * theme doesn't have falls back to the one shipped with the extension; a template
 * the project names explicitly must exist.
 */
final readonly class TemplateRenderer
{
    public const NAMESPACE = 'bolt-sitemap';

    public function __construct(
        private Environment $twig,
        private Config $boltConfig,
    ) {
    }

    /**
     * @param string $template key of `templates`: index, urlset, stylesheet or script
     * @param array<string, mixed> $context
     */
    public function render(SitemapConfig $config, string $template, array $context): string
    {
        $name = $config->templates[$template] ?? SitemapConfig::DEFAULT_TEMPLATES[$template];
        $shipped = SitemapConfig::DEFAULT_TEMPLATES[$template];
        $templates = $name === $shipped ? [$name, '@' . self::NAMESPACE . '/' . $shipped] : [$name];

        $this->addThemePath();

        return $this->twig->resolveTemplate($templates)
            ->render($context);
    }

    private function addThemePath(): void
    {
        $themePath = $this->boltConfig->getPath('theme');
        $templateDirectory = $this->boltConfig->get('theme/template_directory');
        if (is_string($templateDirectory) && $templateDirectory !== '') {
            $themePath .= DIRECTORY_SEPARATOR . $templateDirectory;
        }

        if (! is_dir($themePath)) {
            return;
        }

        $loader = $this->twig->getLoader();
        foreach ($loader instanceof ChainLoader ? $loader->getLoaders() : [$loader] as $filesystemLoader) {
            // Once per process: long-running runtimes keep the loader between requests.
            if ($filesystemLoader instanceof FilesystemLoader && ! in_array($themePath, $filesystemLoader->getPaths(), true)) {
                $filesystemLoader->prependPath($themePath);
            }
        }
    }
}
