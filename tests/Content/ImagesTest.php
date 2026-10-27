<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use Bolt\Entity\Field\TextField;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Content\Images;
use Tomvondracek\Sitemap\SitemapConfig;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class ImagesTest extends TestCase
{
    public function testFirstImageByDefault(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('products'));
        Fixtures::addField($record, new TextField(), 'title', [
            'cs' => ['Okno'],
        ]);
        Fixtures::addImage($record, 'image', '');
        Fixtures::addImagelist($record, 'gallery', ['2026-10/okno 1.jpg', '2026-10/okno-2.jpg']);
        Fixtures::addImage($record, 'drawing', 'drawing.png');

        self::assertSame(['https://example.com/files/2026-10/okno%201.jpg'], $this->images()->of($record, SitemapConfig::fromArray([])));
    }

    public function testConfiguredFields(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('products'));
        Fixtures::addImage($record, 'image', 'main.jpg');
        Fixtures::addImagelist($record, 'gallery', ['a.jpg', 'main.jpg', 'b.jpg']);
        Fixtures::addField($record, new TextField(), 'title', [
            'cs' => ['Okno'],
        ]);

        $config = SitemapConfig::fromArray([
            'images' => [
                'fields' => [
                    'products' => ['gallery', 'image', 'title', 'missing'],
                ],
            ],
        ]);

        self::assertSame([
            'https://example.com/files/a.jpg',
            'https://example.com/files/main.jpg',
            'https://example.com/files/b.jpg',
        ], $this->images()->of($record, $config), 'every image of the fields, once, in order');
    }

    public function testCappedAtOneThousand(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('products'));
        Fixtures::addImagelist($record, 'gallery', array_map(static fn (int $n): string => $n . '.jpg', range(1, 1001)));

        $config = SitemapConfig::fromArray([
            'images' => [
                'fields' => [
                    'products' => ['gallery'],
                ],
            ],
        ]);

        self::assertCount(Images::MAX_PER_URL, $this->images()->of($record, $config));
    }

    public function testDisabled(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('products'));
        Fixtures::addImage($record, 'image', 'main.jpg');

        self::assertSame([], $this->images()->of($record, SitemapConfig::fromArray([
            'images' => [
                'enabled' => false,
            ],
        ])));
    }

    public function testNoImages(): void
    {
        $record = Fixtures::content(1, Fixtures::contentType('products'));
        Fixtures::addField($record, new TextField(), 'title', [
            'cs' => ['Okno'],
        ]);

        self::assertSame([], $this->images()->of($record, SitemapConfig::fromArray([])));
    }

    private function images(): Images
    {
        return new Images(Fixtures::siteUrl());
    }
}
