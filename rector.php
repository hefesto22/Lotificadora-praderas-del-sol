<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

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

        // §12: las migraciones ya aplicadas son INMUTABLES — se corrige con
        // una migración nueva, nunca editando una vieja. Dejar que Rector
        // las reescriba rompería esa garantía y, peor, produciría diffs en
        // archivos que en producción ya corrieron.
        __DIR__.'/database/migrations',
    ])

    // PHP 8.5. UP_TO_PHP_84 queda para las modernizaciones acumuladas de
    // las versiones anteriores; php85: true agrega las propias de 8.5.
    ->withPhpSets(php85: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_84,

        // Reglas de actualización de Laravel hasta la 13.
        LaravelLevelSetList::UP_TO_LARAVEL_130,

        // Calidad idiomática de Laravel.
        LaravelSetList::LARAVEL_CODE_QUALITY,

        // Model::where(...) pasa a Model::query()->where(...). Es el estilo
        // que ya usa el código nuevo del dominio, y evita el método mágico
        // que PHPStan no puede tipar.
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
    ])

    // NO se activan a propósito, con su razón:
    //
    //  LARAVEL_STATIC_TO_INJECTION — convierte facades en inyección por
    //  constructor. Los Resources y Schemas de Filament son estáticos por
    //  diseño; el set los rompería.
    //
    //  LARAVEL_IF_HELPERS — reescribe `if (!$x) { throw ... }` como
    //  `throw_if(...)`. Es legítimo, pero cambia la legibilidad de los
    //  guards del dominio, que son justo donde queremos leer la condición
    //  y el motivo por separado.
    //
    //  LARAVEL_TESTING — apunta a tests estilo PHPUnit; la suite es Pest.

    ->withRules([
        // Reglas puntuales, si alguna vez hace falta.
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
