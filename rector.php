<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Set\LaravelSetList;

/**
 * Rector keeps the codebase current across PHP and Laravel majors. It runs in
 * CI with --dry-run: a failing job means the code has drifted from the idioms
 * the rest of the platform uses.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',

        // `app()` is the helper the framework's own documentation uses
        // everywhere; rewriting it to `resolve()` only churns diffs.
        AppToResolveRector::class,

        // `$x === null` reads more plainly than `! $x instanceof Model` when
        // the parameter is already typed, and the two are equivalent here.
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipNegatedTernaryInstanceofRector::class,

        // Architecture tests name namespaces and third-party classes as
        // strings on purpose: importing them would make the file violate the
        // very rule it enforces.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/tests/Arch',
        ],
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets(php84: true);
