<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Segment;

/**
 * One part of the sitemap, served as /sitemap-<name>.xml and, when it has more
 * URLs than fit one file, continued in /sitemap-<name>-2.xml and so on.
 */
final readonly class Segment
{
    /**
     * The homepage, singletons and static routes.
     */
    public const MAIN = 'main';

    /**
     * A ContentType: its listing page and its records.
     */
    public const CONTENT_TYPE = 'contenttype';

    /**
     * The pages of a taxonomy.
     */
    public const TAXONOMY = 'taxonomy';

    /**
     * @param string $type one of the constants
     * @param string|null $key the ContentType or taxonomy slug
     * @param int<1, max> $chunks number of files
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $key = null,
        public int $chunks = 1,
    ) {
    }

    /**
     * The `<name>` of /sitemap-<name>.xml for a chunk, counted from 1.
     */
    public function fileName(int $chunk): string
    {
        return $chunk === 1 ? $this->name : $this->name . '-' . $chunk;
    }
}
