<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Typed, validated view of `config/extensions/tomvondracek-sitemap.yaml`.
 *
 * A missing or empty value means the default; a value of the wrong type or an
 * unknown key is a configuration error, so a typo can't go unnoticed.
 */
final readonly class SitemapConfig
{
    public const DEFAULT_MAIN_SEGMENT = 'main';
    public const DEFAULT_CHUNK_SIZE = 10000;
    public const DEFAULT_MAX_AGE = 3600;

    /**
     * The protocol's limit of URLs per sitemap file.
     */
    public const MAX_CHUNK_SIZE = 50000;

    /**
     * Template names looked up in the theme; while the theme has no such file, the
     * one shipped with the extension is used.
     */
    public const DEFAULT_TEMPLATES = [
        'index' => 'sitemap-index.xml.twig',
        'urlset' => 'sitemap-urlset.xml.twig',
        'stylesheet' => 'sitemap.css.twig',
        'script' => 'sitemap-ui.js.twig',
    ];

    private const KEYS = [
        'listings',
        'exclude_contenttypes',
        'exclude_listings',
        'taxonomies',
        'static_routes',
        'exclude_static_routes',
        'static_route_lastmod',
        'main_segment',
        'chunk_size',
        'max_age',
        'hreflang',
        'images',
        'seo',
        'human_view',
        'templates',
    ];

    /**
     * @param list<string> $excludeContentTypes
     * @param list<string> $excludeListings
     * @param list<string> $taxonomies
     * @param list<string> $excludeStaticRoutes
     * @param array<string, DateTimeImmutable> $staticRouteLastmod route name => lastmod
     * @param array<string, list<string>> $imageFields ContentType slug => field names
     * @param bool|string $stylesheet false = none, true = the bundled one, or the URL of your own
     * @param bool|string $script false = none, true = the bundled one, or the URL of your own
     * @param array{index: string, urlset: string, stylesheet: string, script: string} $templates
     */
    private function __construct(
        public bool $listings,
        public array $excludeContentTypes,
        public array $excludeListings,
        public array $taxonomies,
        public bool $staticRoutes,
        public array $excludeStaticRoutes,
        public array $staticRouteLastmod,
        public string $mainSegment,
        public int $chunkSize,
        public int $maxAge,
        public bool $xDefault,
        public bool $images,
        public array $imageFields,
        public bool $respectNoindex,
        public bool|string $stylesheet,
        public bool|string $script,
        public array $templates,
    ) {
    }

    /**
     * @param array<array-key, mixed> $config raw (possibly partial) YAML config
     *
     * @throws InvalidArgumentException for unknown keys and invalid values
     */
    public static function fromArray(array $config): self
    {
        self::assertKnownKeys('', $config, self::KEYS);

        $hreflang = self::map('hreflang', $config['hreflang'] ?? null, ['x_default']);
        $images = self::map('images', $config['images'] ?? null, ['enabled', 'fields']);
        $seo = self::map('seo', $config['seo'] ?? null, ['respect_noindex']);
        $humanView = self::map('human_view', $config['human_view'] ?? null, ['stylesheet', 'script']);
        $templates = self::map('templates', $config['templates'] ?? null, array_keys(self::DEFAULT_TEMPLATES));

        return new self(
            listings: self::bool('listings', $config['listings'] ?? null) ?? true,
            excludeContentTypes: self::strings('exclude_contenttypes', $config['exclude_contenttypes'] ?? null),
            excludeListings: self::strings('exclude_listings', $config['exclude_listings'] ?? null),
            taxonomies: self::strings('taxonomies', $config['taxonomies'] ?? null),
            staticRoutes: self::bool('static_routes', $config['static_routes'] ?? null) ?? true,
            excludeStaticRoutes: self::strings('exclude_static_routes', $config['exclude_static_routes'] ?? null),
            staticRouteLastmod: self::dates('static_route_lastmod', $config['static_route_lastmod'] ?? null),
            mainSegment: self::segmentName($config['main_segment'] ?? null),
            chunkSize: self::int('chunk_size', $config['chunk_size'] ?? null, 1, self::MAX_CHUNK_SIZE) ?? self::DEFAULT_CHUNK_SIZE,
            maxAge: self::int('max_age', $config['max_age'] ?? null, 0) ?? self::DEFAULT_MAX_AGE,
            xDefault: self::bool('hreflang.x_default', $hreflang['x_default'] ?? null) ?? true,
            images: self::bool('images.enabled', $images['enabled'] ?? null) ?? true,
            imageFields: self::imageFields($images['fields'] ?? null),
            respectNoindex: self::bool('seo.respect_noindex', $seo['respect_noindex'] ?? null) ?? true,
            stylesheet: self::asset('human_view.stylesheet', $humanView['stylesheet'] ?? null),
            script: self::asset('human_view.script', $humanView['script'] ?? null),
            templates: [
                'index' => self::string('templates.index', $templates['index'] ?? null) ?? self::DEFAULT_TEMPLATES['index'],
                'urlset' => self::string('templates.urlset', $templates['urlset'] ?? null) ?? self::DEFAULT_TEMPLATES['urlset'],
                'stylesheet' => self::string('templates.stylesheet', $templates['stylesheet'] ?? null) ?? self::DEFAULT_TEMPLATES['stylesheet'],
                'script' => self::string('templates.script', $templates['script'] ?? null) ?? self::DEFAULT_TEMPLATES['script'],
            ],
        );
    }

    public function includesRecords(string $contentType): bool
    {
        return ! in_array($contentType, $this->excludeContentTypes, true);
    }

    public function includesListing(string $contentType): bool
    {
        return $this->listings && ! in_array($contentType, $this->excludeListings, true);
    }

    /**
     * The image fields configured for a ContentType; null = its first image, the
     * one Bolt's `|image` filter returns.
     *
     * @return list<string>|null
     */
    public function imageFieldsFor(string $contentType): ?array
    {
        return $this->imageFields[$contentType] ?? null;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param list<string> $known
     */
    private static function assertKnownKeys(string $parent, array $config, array $known): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($config)), $known);
        if ($unknown === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown sitemap config key(s) "%s"; the known ones are "%s". Check config/extensions/%s.yaml.',
            implode('", "', array_map(static fn (string $key): string => $parent === '' ? $key : $parent . '.' . $key, $unknown)),
            implode('", "', array_map(static fn (string $key): string => $parent === '' ? $key : $parent . '.' . $key, $known)),
            SitemapConfigLoader::CONFIG_BASENAME
        ));
    }

    /**
     * @param list<string> $known
     *
     * @return array<array-key, mixed>
     */
    private static function map(string $key, mixed $value, array $known): array
    {
        $map = self::anyMap($key, $value);
        self::assertKnownKeys($key, $map, $known);

        return $map;
    }

    /**
     * A map with keys of the project's choosing, such as route names.
     *
     * @return array<array-key, mixed>
     */
    private static function anyMap(string $key, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw self::invalid($key, 'a map', $value);
        }

        return $value;
    }

    private static function bool(string $key, mixed $value): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        throw self::invalid($key, 'true or false', $value);
    }

    private static function string(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw self::invalid($key, 'a string', $value);
        }

        return mb_trim($value) === '' ? null : mb_trim($value);
    }

    /**
     * @return list<string>
     */
    private static function strings(string $key, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($key, 'a list of names', $value);
        }

        $strings = [];
        foreach ($value as $item) {
            $string = is_string($item) ? self::string($key, $item) : throw self::invalid($key, 'a list of names', $value);
            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return array_values(array_unique($strings));
    }

    private static function int(string $key, mixed $value, int $min, int $max = PHP_INT_MAX): ?int
    {
        if ($value === null) {
            return null;
        }

        $int = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if (! is_int($int) || $int < $min || $int > $max) {
            $range = $max === PHP_INT_MAX ? sprintf('%d or more', $min) : sprintf('from %d to %d', $min, $max);

            throw self::invalid($key, 'a whole number ' . $range, $value);
        }

        return $int;
    }

    /**
     * Letters, digits, `_` and `-`: it becomes part of a file name, /sitemap-<name>.xml.
     */
    private static function segmentName(mixed $value): string
    {
        $name = self::string('main_segment', $value) ?? self::DEFAULT_MAIN_SEGMENT;
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $name) !== 1) {
            throw self::invalid('main_segment', 'letters, digits, "_" and "-"', $value);
        }

        return $name;
    }

    private static function asset(string $key, mixed $value): bool|string
    {
        if ($value === null || is_bool($value)) {
            return $value ?? false;
        }

        return self::string($key, $value) ?? throw self::invalid($key, 'true, false or a URL', $value);
    }

    /**
     * @return array<string, DateTimeImmutable>
     */
    private static function dates(string $key, mixed $value): array
    {
        $dates = [];
        foreach (self::anyMap($key, $value) as $route => $date) {
            $dates[(string) $route] = self::date($key . '.' . $route, $date);
        }

        return $dates;
    }

    /**
     * YAML turns an unquoted date (2026-10-01) into a Unix timestamp; a quoted one
     * stays a string.
     */
    private static function date(string $key, mixed $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone($utc);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($utc);
        }

        if (is_string($value) && mb_trim($value) !== '') {
            try {
                return (new DateTimeImmutable(mb_trim($value), $utc))->setTimezone($utc);
            } catch (Exception) {
            }
        }

        throw self::invalid($key, 'a date, such as 2026-10-01 or 2026-10-01T08:00:00+02:00', $value);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function imageFields(mixed $value): array
    {
        $fields = [];
        foreach (self::anyMap('images.fields', $value) as $contentType => $names) {
            $fields[(string) $contentType] = self::strings('images.fields.' . $contentType, $names);
        }

        return $fields;
    }

    private static function invalid(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The sitemap `%s` must be %s, %s given. Check config/extensions/%s.yaml.',
            $key,
            $expected,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            SitemapConfigLoader::CONFIG_BASENAME
        ));
    }
}
