<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests\Url;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Sitemap\Url\UrlNormalizer;

final class UrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://example.com/about', 'https://example.com/about'];
        yield 'diacritics in the path' => ['https://example.com/služby?a=1&b=2', 'https://example.com/slu%C5%BEby?a=1&b=2'];
        yield 'IDN host in Punycode' => ['https://háčky.cz/služby', 'https://xn--hky-ela4t.cz/slu%C5%BEby'];
        yield 'space' => ['https://example.com/files/my photo.jpg', 'https://example.com/files/my%20photo.jpg'];
        yield 'existing escapes kept' => ['https://example.com/slu%C5%BEby', 'https://example.com/slu%C5%BEby'];
        yield 'lone percent' => ['https://example.com/100%', 'https://example.com/100%25'];
        yield 'port' => ['http://localhost:8000/sitemap.xml', 'http://localhost:8000/sitemap.xml'];
        yield 'case of scheme and host' => ['HTTPS://Example.COM/About', 'https://example.com/About'];
        yield 'user info' => ['https://us er@example.com/', 'https://us%20er@example.com/'];
        yield 'fragment' => ['https://example.com/a#část', 'https://example.com/a#%C4%8D%C3%A1st'];
        yield 'relative' => ['/služby', '/slu%C5%BEby'];
    }

    #[DataProvider('urls')]
    public function testNormalize(string $url, string $expected): void
    {
        self::assertSame($expected, UrlNormalizer::normalize($url));
        self::assertSame($expected, UrlNormalizer::normalize($expected), 'normalizing twice changes nothing');
    }
}
