<?php

use App\Http\Middleware\ResolveWorkspaceRole;
use App\Http\Middleware\VerifyEngineCallbackSignature;
use App\Http\Response\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'workspace.role' => ResolveWorkspaceRole::class,
            'engine.signature' => VerifyEngineCallbackSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ApiException subclasses self-render — no registration needed.

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::notFound();
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::unauthorized();
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::validationFailed($e->errors());
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage() ?: 'An error occurred.')
                    ->send($e->getStatusCode());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $message = app()->isProduction()
                    ? 'Internal server error.'
                    : $e->getMessage();

                return ApiResponse::serverError($message);
            }
        });
    })->create();
