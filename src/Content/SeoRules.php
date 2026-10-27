<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Entity\Content;
use Symfony\Contracts\Service\ResetInterface;
use Tomvondracek\Sitemap\SitemapConfigLoader;
use Tomvondracek\Sitemap\Url\SiteUrl;

/**
 * Which pages the bolt-seo extension (appolodev/bolt-seo) keeps out of search
 * results: a sitemap must not list them (spec §3). Without that extension
 * installed, every page is listed.
 *
 * The robots and canonical values are resolved like bolt-seo's `Seo` class does:
 * `override_default` (by route name; for listings also by ContentType slug; for a
 * `<name>_locale` route also by `<name>`, with its `locales` merged over it),
 * then the record's `seo` field, then `default` (robots only: a site-wide
 * canonical would be a misconfiguration, not a page pointing elsewhere).
 *
 * Only bolt-seo's config file and field values are read, never its classes, so
 * the sitemap doesn't depend on bolt-seo. BoltSeoContractTest checks that the
 * result matches what bolt-seo's own `Seo` class renders.
 */
final class SeoRules implements ResetInterface
{
    /**
     * A string, not `::class` with an import: bolt-seo may not be installed.
     */
    public const SEO_EXTENSION = 'Appolo\BoltSeo\Extension';

    public const SEO_CONFIG_BASENAME = 'appolo-boltseo';

    /** @var array<array-key, mixed>|null */
    private ?array $seoConfig = null;

    public function __construct(
        private readonly SitemapConfigLoader $configLoader,
        private readonly SiteUrl $siteUrl,
        /** Null = whether bolt-seo is installed. */
        private readonly ?bool $installed = null,
    ) {
    }

    /**
     * Reads bolt-seo's config again on the next request of a long-running process.
     */
    public function reset(): void
    {
        $this->seoConfig = null;
    }

    /**
     * Whether the page at `$url`, served by `$route`, is `noindex` or has another
     * canonical URL.
     *
     * @param string|null $listing the ContentType slug, when the page is its listing
     */
    public function excludes(string $url, string $route, string $locale, ?Content $record = null, ?string $listing = null): bool
    {
        $config = $this->seoConfig();
        if ($config === null) {
            return false;
        }

        $override = $this->override($config, $route, $locale, $listing);
        $field = $record === null ? [] : $this->fieldData($record, $locale);
        $default = is_array($config['default'] ?? null) ? $config['default'] : [];

        $robots = self::value($override, 'robots') ?? self::value($field, 'robots') ?? self::value($default, 'robots') ?? '';
        if (preg_match('/\b(noindex|none)\b/i', $robots) === 1) {
            return true;
        }

        $canonical = self::value($override, 'canonical') ?? self::value($field, 'canonical');

        return $canonical !== null && mb_rtrim($this->siteUrl->absolute($canonical), '/') !== mb_rtrim($url, '/');
    }

    /**
     * @return array<array-key, mixed>|null null when bolt-seo is not installed
     */
    private function seoConfig(): ?array
    {
        if (! ($this->installed ?? class_exists(self::SEO_EXTENSION))) {
            return null;
        }

        return $this->seoConfig ??= $this->configLoader->loadOther(self::SEO_CONFIG_BASENAME);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private function override(array $config, string $route, string $locale, ?string $listing): array
    {
        $overrides = is_array($config['override_default'] ?? null) ? $config['override_default'] : [];

        $override = $overrides[$route] ?? null;
        if ($override === null && $listing !== null && in_array($route, ['listing', 'listing_locale'], true)) {
            $override = $overrides[$listing] ?? null;
        } elseif ($override === null && str_ends_with($route, '_locale')) {
            $override = $overrides[mb_substr($route, 0, -mb_strlen('_locale'))] ?? null;
        }

        if (! is_array($override)) {
            return [];
        }

        $locales = $override['locales'] ?? null;
        unset($override['locales']);
        $localeOverride = is_array($locales) && is_array($locales[$locale] ?? null) ? $locales[$locale] : [];

        return array_merge($override, $localeOverride);
    }

    /**
     * The decoded JSON of the record's `seo` field in a locale.
     *
     * @return array<array-key, mixed>
     */
    private function fieldData(Content $record, string $locale): array
    {
        if (! $record->hasField('seo')) {
            return [];
        }

        $field = $record->getField('seo');
        $currentLocale = $field->getLocale();
        $field->setLocale($locale);
        $json = (string) $field;
        $field->setLocale($currentLocale);

        $decoded = $json === '' ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function value(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
