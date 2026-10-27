<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\State;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Tomvondracek\Sitemap\State\StateStore;

final class StateStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bolt-sitemap-state-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFirstSightHasNoChangeDate(): void
    {
        self::assertNull($this->store()->changedAt('sitemap:pages', 'abc', new DateTimeImmutable('2026-10-01')));
        self::assertNull($this->store()->changedAt('sitemap:pages', 'abc', new DateTimeImmutable('2026-10-02')), 'still unknown while nothing changed');
    }

    public function testAChangeIsRememberedUntilTheNextOne(): void
    {
        $store = $this->store();
        $store->changedAt('sitemap:pages', 'abc', new DateTimeImmutable('2026-10-01'));

        $changed = $store->changedAt('sitemap:pages', 'abd', new DateTimeImmutable('2026-10-02 10:00:00+02:00'));
        self::assertSame('2026-10-02T08:00:00+00:00', $changed?->format(DATE_ATOM));

        $later = $this->store()->changedAt('sitemap:pages', 'abd', new DateTimeImmutable('2026-10-05'));
        self::assertSame('2026-10-02T08:00:00+00:00', $later?->format(DATE_ATOM), 'kept in the file, across instances');

        self::assertNull($store->changedAt('listing:pages', 'abd', new DateTimeImmutable('2026-10-05')), 'keys are independent');
    }

    public function testCorruptFileStartsOver(): void
    {
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . '/state.json', '{"sitemap:pages": "x", "other": {"fingerprint": "a", "changedAt": "garbage"}}');

        self::assertNull($this->store()->changedAt('sitemap:pages', 'abc', new DateTimeImmutable('2026-10-01')));
        self::assertNull($this->store()->changedAt('other', 'a', new DateTimeImmutable('2026-10-01')), 'an unreadable date is unknown');
    }

    public function testUnwritableLocationKnowsNothing(): void
    {
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . '/file', '');

        $store = new StateStore($this->dir . '/file/state.json');

        self::assertNull($store->changedAt('sitemap:pages', 'abc', new DateTimeImmutable('2026-10-01')));
    }

    private function store(): StateStore
    {
        return new StateStore($this->dir . '/state.json');
    }
}
