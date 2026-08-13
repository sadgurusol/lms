<?php

use App\Http\Middleware\EnsureClientScope;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'client.scope' => EnsureClientScope::class,
            'staff' => EnsureStaff::class,
            'client' => \App\Http\Middleware\AuthenticateClient::class,
        ]);

        // Inertia shares auth state with every studio page.
        $middleware->web(append: [HandleInertiaRequests::class]);

        // There is no route named `login`: the studio owns the only session
        // login, and the API is token-only.
        $middleware->redirectGuestsTo(fn () => route('studio.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
