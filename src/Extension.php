<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use Bolt\Extension\BaseExtension;

final class Extension extends BaseExtension
{
    public function getName(): string
    {
        return 'XML Sitemap';
    }

    public function initialize(): void
    {
        // The @bolt-sitemap namespace is registered at container level, in the
        // extension's config/services.yaml (copied to
        // config/packages/extension_bolt-sitemap.yaml by `extensions:configure`),
        // so it also resolves on the CLI. This runtime registration is kept as a
        // fallback for projects that have not run `extensions:configure` yet.
        $this->addTwigNamespace('bolt-sitemap');
    }
}
