<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Configuration\Config;
use Bolt\Entity\Content;

/**
 * What Bolt serves at `/`: the `homepage` setting of the theme or of
 * config/bolt/config.yaml, `<contenttype>` or `<contenttype>/<id or slug>`.
 *
 * Mirrors HomepageController (which setting wins) and ContentHelper::isHomepage()
 * (which record it is), so the record is listed once, as `/`.
 */
final readonly class Homepage
{
    public function __construct(
        private Config $boltConfig,
    ) {
    }

    /**
     * The ContentType the homepage shows, null when the setting is empty or names
     * no ContentType.
     */
    public function contentType(): ?string
    {
        $contentType = $this->config()[0];
        $definition = $contentType === null ? null : $this->boltConfig->getContentType($contentType);

        return $definition?->getSlug();
    }

    /**
     * Whether the setting names something; when the ContentType doesn't exist,
     * Bolt can't render `/` at all.
     */
    public function isConfigured(): bool
    {
        return $this->config()[0] !== null;
    }

    /**
     * The id or slug after the `/`, if any.
     */
    public function idOrSlug(): ?string
    {
        return $this->config()[1];
    }

    /**
     * Whether `/` shows a listing of the ContentType rather than one record.
     */
    public function isListing(): bool
    {
        $contentType = $this->contentType();
        if ($contentType === null || $this->idOrSlug() !== null) {
            return false;
        }

        return $this->boltConfig->getContentType($contentType)?->get('singleton') !== true;
    }

    public function isHomepage(Content $content): bool
    {
        [$contentType, $idOrSlug] = $this->config();
        if ($contentType === null || ($contentType !== $content->getContentTypeSlug() && $contentType !== $content->getContentTypeSingularSlug())) {
            return false;
        }

        if ($idOrSlug === null) {
            return $content->getDefinition()?->get('singleton') === true;
        }

        return $idOrSlug === $content->getSlug() || $idOrSlug === (string) $content->getId();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function config(): array
    {
        $setting = $this->boltConfig->get('theme/homepage');
        if (! is_string($setting) || mb_trim($setting) === '') {
            $setting = $this->boltConfig->get('general/homepage');
        }

        if (! is_string($setting) || mb_trim($setting, " \n\r\t\v\0/") === '') {
            return [null, null];
        }

        $parts = explode('/', mb_trim($setting, " \n\r\t\v\0/"), 2);
        $idOrSlug = isset($parts[1]) && mb_trim($parts[1]) !== '' ? mb_trim($parts[1]) : null;

        return [mb_trim($parts[0]), $idOrSlug];
    }
}
