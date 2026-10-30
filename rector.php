<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;

return RectorConfig::configure()
    ->withCache('./var/cache/rector', FileCacheStorage::class)
    ->withPaths(['./src', './tests'])
    ->withImportNames()
    ->withSkip([
        // bolt-seo is optional: its class name stays a string, never an import.
        StringClassNameToClassConstantRector::class => [__DIR__ . '/src/Content/SeoRules.php'],
        // getSubscribedEvents() lets the tests check the priority against the session listener.
        EventSubscriberToAttributeRector::class,
    ])
    ->withParallel(timeoutSeconds: 180, jobSize: 10)
    ->withPhpSets()
    ->withPreparedSets(
        typeDeclarations: true,
        symfonyCodeQuality: true,
    )
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
    );
