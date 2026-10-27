<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * bolt-seo is optional: the extension's code may name it only as a string, so
 * nothing loads a bolt-seo class and a project without it works the same.
 */
final class OptionalDependencyTest extends TestCase
{
    public function testSourceNeverImportsOrCallsBoltSeo(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));

        $references = [];
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                // A name token is code (`use Appolo\…`, `new \Appolo\…`, `Appolo\…::class`);
                // the class name in a string literal is a T_CONSTANT_ENCAPSED_STRING.
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_starts_with(mb_ltrim($token[1], '\\'), 'Appolo\\')) {
                    $references[] = $file->getFilename() . ':' . $token[2] . ' ' . $token[1];
                }
            }
        }

        self::assertSame([], $references);
    }

    public function testComposerOnlySuggestsBoltSeo(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        self::assertArrayNotHasKey('appolodev/bolt-seo', (array) ($composer['require'] ?? []));
        self::assertArrayHasKey('appolodev/bolt-seo', (array) ($composer['suggest'] ?? []));
    }
}
