<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps the messages logged, without their context.
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
