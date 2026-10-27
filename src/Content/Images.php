<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Content;

use Bolt\Entity\Content;
use Bolt\Entity\Field;
use Bolt\Entity\Field\ImageField;
use Bolt\Entity\ListFieldInterface;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\Url\SiteUrl;

/**
 * The images of a record for `<image:image>` (spec §4.3): by default its first
 * image, the one Bolt's `|image` filter returns; with `images.fields` configured
 * for its ContentType, every image in those image and imagelist fields.
 */
final readonly class Images
{
    /**
     * Most `<image:image>` entries one `<url>` may have.
     */
    public const MAX_PER_URL = 1000;

    public function __construct(
        private SiteUrl $siteUrl,
    ) {
    }

    /**
     * @return list<string> absolute image URLs, without duplicates
     */
    public function of(Content $record, SitemapConfig $config): array
    {
        if (! $config->images) {
            return [];
        }

        $names = $config->imageFieldsFor($record->getContentTypeSlug());

        $paths = [];
        if ($names === null) {
            foreach ($record->getFields() as $field) {
                $paths = $this->collect($field, true);
                if ($paths !== []) {
                    break;
                }
            }
        } else {
            foreach ($names as $name) {
                if ($record->hasField($name)) {
                    $paths = [...$paths, ...$this->collect($record->getField($name), false)];
                }
            }
        }

        $urls = array_values(array_unique(array_map($this->siteUrl->absolute(...), $paths)));

        return array_slice($urls, 0, self::MAX_PER_URL);
    }

    /**
     * Paths of the images in a field, recursing into imagelists, sets and
     * collections.
     *
     * @return list<string>
     */
    private function collect(Field $field, bool $firstOnly): array
    {
        if ($field instanceof ImageField) {
            $path = $field->get('filename') ? (string) $field : '';

            return $path === '' ? [] : [$path];
        }

        if (! $field instanceof ListFieldInterface) {
            return [];
        }

        $paths = [];
        foreach ($field->getValue() as $subField) {
            if (! $subField instanceof Field) {
                continue;
            }

            $paths = [...$paths, ...$this->collect($subField, $firstOnly)];
            if ($firstOnly && $paths !== []) {
                return $paths;
            }
        }

        return $paths;
    }
}
