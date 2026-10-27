<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\State;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers when the set of URLs in a sitemap, or the set of records on a listing
 * page, last changed, so a removal moves `<lastmod>` forward too. The newest
 * `<lastmod>` of the remaining URLs alone misses removals.
 *
 * Kept in a small JSON file next to Bolt's own data, outside the cache, so
 * `cache:clear` doesn't forget it. A set seen for the first time has no known
 * change date: nothing is invented, the URLs' own dates apply.
 */
final readonly class StateStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/data/tomvondracek-sitemap.json')]
        private string $file,
    ) {
    }

    /**
     * When the set identified by `$key` last changed, given its current
     * fingerprint; `$now` when it changed just now, null when it was never seen
     * before.
     */
    public function changedAt(string $key, string $fingerprint, DateTimeInterface $now): ?DateTimeImmutable
    {
        $directory = dirname($this->file);
        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            return null;
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_EX);
            $state = $this->read($handle);
            $entry = $state[$key] ?? null;

            if ($entry !== null && $entry['fingerprint'] === $fingerprint) {
                return self::date($entry['changedAt']);
            }

            $changedAt = $entry === null ? null : DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'));
            $state[$key] = [
                'fingerprint' => $fingerprint,
                'changedAt' => $changedAt?->format(DateTimeInterface::ATOM),
            ];
            $this->write($handle, $state);

            return $changedAt;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array<string, array{fingerprint: string, changedAt: string|null}>
     */
    private function read($handle): array
    {
        rewind($handle);
        $json = stream_get_contents($handle);
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        $state = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $entry) {
            if (is_array($entry) && is_string($entry['fingerprint'] ?? null)) {
                $state[(string) $key] = [
                    'fingerprint' => $entry['fingerprint'],
                    'changedAt' => is_string($entry['changedAt'] ?? null) ? $entry['changedAt'] : null,
                ];
            }
        }

        return $state;
    }

    /**
     * @param resource $handle
     * @param array<string, array{fingerprint: string, changedAt: string|null}> $state
     */
    private function write($handle, array $state): void
    {
        ksort($state);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        fflush($handle);
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
