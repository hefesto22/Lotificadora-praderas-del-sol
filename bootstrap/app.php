<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // API routes opcionales — descomentar y crear routes/api.php cuando se necesite.
            // \Illuminate\Support\Facades\Route::middleware('api')
            //     ->prefix('api/v1')
            //     ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Confiar en proxies (necesario detrás de Cloudflare, Nginx, etc.).
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sentry captura excepciones automáticamente vía su Service Provider.
        // Si el binding existe (DSN configurado), reportamos también desde aquí.
        if (app()->bound('sentry')) {
            $exceptions->report(function (Throwable $e): void {
                Integration::captureUnhandledException($e);
            });
        }
    })
    ->booting(function (): void {
        // Rate limiters — buckets disponibles vía middleware `throttle:<nombre>`.
        // Aplicar en rutas con: ->middleware('throttle:api')
        RateLimiter::for(
            'api',
            fn (Request $request): Limit => Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for(
            'login',
            fn (Request $request): Limit => Limit::perMinute(5)
                ->by($request->ip())
        );

        RateLimiter::for(
            'exports',
            fn (Request $request): Limit => Limit::perHour(10)
                ->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for(
            'pdfs',
            fn (Request $request): Limit => Limit::perMinute(20)
                ->by($request->user()?->id ?: $request->ip())
        );
    })
    ->create();
