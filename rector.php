<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',
        __DIR__.'/public/build',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
    ])
    ->withRules([
        // Reglas de modernización seguras
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: false,    // Pint se encarga del estilo
        typeDeclarations: true,
        privatization: true,
        naming: false,         // demasiado disruptivo, ejecutar manual
        instanceOf: true,
        earlyReturn: true,
        strictBooleans: false  // ruidoso, evaluar después
    )
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true
    );
