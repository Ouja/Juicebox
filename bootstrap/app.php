<?php

use App\Exceptions\WeatherUnavailableException;
use App\Http\Middleware\EnsurePostOwner;
use App\Http\Middleware\WrapApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(WrapApiResponse::class);
        $middleware->alias([
            'post.owner' => EnsurePostOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request) => WrapApiResponse::wrap($response, $request));
        $exceptions->render(function (WeatherUnavailableException $exception, Request $request) {
            return response()->json(['message' => 'Weather data is temporarily unavailable. Please try again later.'], 503);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );
    })->create();
