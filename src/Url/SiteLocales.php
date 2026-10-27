<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

use Bolt\Configuration\Content\ContentType;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * The site's default locale (`%locale%`, served without a URL prefix) and the
 * languages a ContentType is published in.
 */
final readonly class SiteLocales
{
    public function __construct(
        private ContainerBagInterface $parameters,
    ) {
    }

    public function default(): string
    {
        $locale = $this->parameters->get('locale');

        return is_string($locale) && mb_trim($locale) !== '' ? mb_trim($locale) : 'en';
    }

    /**
     * The ContentType's `locales`, or the site's default locale when it has none.
     *
     * @return non-empty-list<string>
     */
    public function forContentType(?ContentType $contentType): array
    {
        $configured = $contentType?->get('locales');
        $locales = [];
        foreach (is_iterable($configured) ? $configured : [] as $locale) {
            if (is_string($locale) && mb_trim($locale) !== '') {
                $locales[] = mb_trim($locale);
            }
        }

        $locales = array_values(array_unique($locales));

        return $locales === [] ? [$this->default()] : $locales;
    }

    /**
     * The locale of the `x-default` alternate: the site's default if it is one of
     * them, otherwise the first.
     *
     * @param non-empty-list<string> $locales
     */
    public function defaultOf(array $locales): string
    {
        return in_array($this->default(), $locales, true) ? $this->default() : $locales[0];
    }
}
