<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Content;

use Bolt\Configuration\Content\ContentType;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Content\Homepage;
use Tomvondracek\Sitemap\Tests\Fixtures\Fixtures;

final class HomepageTest extends TestCase
{
    public function testSingleton(): void
    {
        $contentType = Fixtures::contentType('homepage', [
            'singleton' => true,
            'singular_slug' => 'homepage',
        ]);
        $homepage = $this->homepage('homepage', [$contentType]);

        self::assertSame('homepage', $homepage->contentType());
        self::assertNull($homepage->idOrSlug());
        self::assertFalse($homepage->isListing());
        self::assertTrue($homepage->isHomepage(Fixtures::content(1, $contentType)));
    }

    public function testRecordBySlugOrId(): void
    {
        $pages = Fixtures::contentType('pages');

        $bySlug = $this->homepage('page/home', [$pages]);
        self::assertSame('pages', $bySlug->contentType(), 'the singular slug names the ContentType too');
        self::assertSame('home', $bySlug->idOrSlug());
        self::assertTrue($bySlug->isHomepage(Fixtures::content(5, $pages, [
            'cs' => 'home',
        ])));
        self::assertFalse($bySlug->isHomepage(Fixtures::content(6, $pages, [
            'cs' => 'about',
        ])));

        $byId = $this->homepage('pages/5', [$pages]);
        self::assertTrue($byId->isHomepage(Fixtures::content(5, $pages)));
        self::assertFalse($byId->isListing());
    }

    public function testListing(): void
    {
        $entries = Fixtures::contentType('entries');
        $homepage = $this->homepage('entries', [$entries]);

        self::assertTrue($homepage->isListing());
        self::assertFalse($homepage->isHomepage(Fixtures::content(1, $entries)), 'no single record is the homepage');
    }

    public function testThemeSettingWins(): void
    {
        $homepage = new Homepage(Fixtures::config([
            'general' => [
                'homepage' => 'pages',
            ],
            'theme' => [
                'homepage' => 'entries',
            ],
            'contenttypes' => [
                'pages' => Fixtures::contentType('pages'),
                'entries' => Fixtures::contentType('entries'),
            ],
        ]));

        self::assertSame('entries', $homepage->contentType());
    }

    public function testNoHomepage(): void
    {
        $pages = Fixtures::contentType('pages');

        foreach ([null, '', '/', 'unknown'] as $setting) {
            $homepage = $this->homepage($setting, [$pages]);
            self::assertNull($homepage->contentType());
            self::assertFalse($homepage->isListing());
        }

        self::assertFalse($this->homepage(null, [$pages])->isHomepage(Fixtures::content(1, $pages)));
        self::assertFalse($this->homepage('entries', [$pages])->isHomepage(Fixtures::content(1, $pages)));
    }

    /**
     * @param list<ContentType> $contentTypes
     */
    private function homepage(?string $setting, array $contentTypes): Homepage
    {
        return new Homepage(Fixtures::boltConfig($contentTypes, [
            'homepage' => $setting,
        ]));
    }
}
