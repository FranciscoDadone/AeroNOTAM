<?php

use App\Http\Middleware\EnsureUserIsAdmin;
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
        // So the app sees the public https:// URL and client IP behind the
        // proxy or tunnel it runs under, rather than the internal ones.
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'whatsapp/webhook',
        ]);

        $middleware->alias(['admin' => EnsureUserIsAdmin::class]);

        // The application has exactly one login form, and it is not at the
        // conventional /login the framework assumes.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
