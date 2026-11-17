<?php

use App\Http\Middleware\BotGuard;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackVisitor;
use App\Support\SeoResolver;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // BotGuard wraps the entire middleware stack (global): route-level
        // throttle middleware is priority-sorted ahead of appended web
        // middleware and throws 429s, so bot counting must run outside it.
        $middleware->append(BotGuard::class);
        // AuthenticateSession invalidates sessions whose stored password
        // hash no longer matches (e.g. after an admin password change), so
        // every other signed-in session is signed out.
        $middleware->web(append: [
            AuthenticateSession::class,
            TrackVisitor::class,
        ]);
        $middleware->alias([
            'track' => TrackVisitor::class,
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception) {
            if (in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)
                && ! request()->is('admin/*')
                && ! request()->expectsJson()) {
                return response()->view('errors.404', [
                    'code' => $response->getStatusCode(),
                    'seo' => SeoResolver::normalize(null, match ($response->getStatusCode()) {
                        404 => 'Page Not Found',
                        429 => 'Too Many Requests',
                        default => 'Something Went Wrong',
                    }, null),
                    'pageType' => 'error',
                ], $response->getStatusCode());
            }

            return $response;
        });
    })->create();
