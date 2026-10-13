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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/admin/login');
        $middleware->redirectUsersTo('/admin');
        // Behind the Nginx that also fronts DSpace. In Docker the proxy connects from the compose
        // network, so TRUSTED_PROXIES (comma list, or "*" when only the proxy can reach this container)
        // overrides the default.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,172.28.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
