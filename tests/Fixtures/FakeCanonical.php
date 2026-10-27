<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Fixtures;

use Bolt\Canonical;

/**
 * Bolt's Canonical, reduced to the homepage URL `general/canonical` gives. Made
 * with ReflectionClass::newInstanceWithoutConstructor(): the real constructor
 * needs a booted Bolt.
 */
final class FakeCanonical extends Canonical
{
    public ?string $homepage = null;

    /**
     * @param array<mixed> $params
     */
    public function get(?string $route = null, array $params = [], bool $absolute = true): ?string
    {
        return $this->homepage;
    }
}
