<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ensure.group.membership' => \App\Http\Middleware\EnsureGroupMembership::class,
        ]);
        
        // Configure API authentication to return JSON instead of redirecting
        $middleware->redirectGuestsTo(fn () => throw new \Illuminate\Auth\AuthenticationException());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ensure API requests always return JSON responses
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
