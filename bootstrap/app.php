<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () { require base_path('routes/mobile.php'); },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->routeIs('login.store') && ! $request->expectsJson()) {
                return redirect()->route('login')
                    ->withErrors(['login' => 'Demasiados intentos. Espera un minuto y vuelve a intentar.']);
            }
        });
    })->create();
