<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * `<lastmod>` values (spec §1.4).
 */
final class Lastmod
{
    /**
     * The later of two dates; null only when both are unknown.
     */
    public static function later(?DateTimeInterface $first, ?DateTimeInterface $second): ?DateTimeImmutable
    {
        $latest = $first === null || ($second !== null && $second > $first) ? $second : $first;

        return $latest === null ? null : DateTimeImmutable::createFromInterface($latest);
    }

    /**
     * W3C Datetime in UTC (`2026-10-22T06:51:00+00:00`): a timezone offset is
     * never hardcoded.
     */
    public static function w3c(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }
}
