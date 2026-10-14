<?php

use App\Exceptions\HttpException;
use App\Exceptions\JwtException;
use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\AdminGuest;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\DetectSlowRequests;
use App\Http\Middleware\PerformanceProfiler;
use App\Http\Middleware\VerifyCoopCallback;
use App\Http\Middleware\ViewAsMember;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            HandleCors::class,
            PerformanceProfiler::class,
            DetectSlowRequests::class,
        ]);

        $middleware->alias([
            'auth.jwt' => Authenticate::class,
            'admin' => AdminAccess::class,
            'admin.guest' => AdminGuest::class,
            'verify.coop.callback' => VerifyCoopCallback::class,
            'view.as.member' => ViewAsMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ],
            ], $e->httpStatus);
        });

        $exceptions->render(function (JwtException $e) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_token',
                    'message' => $e->getMessage(),
                ],
            ], $e->status);
        });
    })->create();
