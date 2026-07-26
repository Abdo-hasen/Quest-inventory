<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/apis/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $createApiErrorResponse = function (int $code, string $message, mixed $data = null, array $headers = []) {
            return response()->json([
                'ok' => false,
                'code' => $code,
                'message' => $message,
                'direct' => null,
                'data' => $data,
            ], $code, $headers);
        };

        $exceptions->renderable(function (NotFoundHttpException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $message = $e->getMessage() ?: __('Resource not found');

                return $createApiErrorResponse(404, $message);
            }
        });

        $exceptions->renderable(function (ModelNotFoundException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $model = class_basename($e->getModel());
                $message = __('The requested :model was not found', ['model' => mb_strtolower($model)]);

                return $createApiErrorResponse(404, $message);
            }
        });

        $exceptions->renderable(function (MethodNotAllowedHttpException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $allowHeader = $e->getHeaders()['Allow'] ?? '';
                $allowedMethods = is_array($allowHeader) ? implode(', ', $allowHeader) : (is_string($allowHeader) ? $allowHeader : 'GET, POST, PUT, DELETE');
                $message = __('Method not allowed. Allowed methods: :methods', ['methods' => $allowedMethods]);

                return $createApiErrorResponse(405, $message);
            }
        });

        $exceptions->renderable(function (HttpException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $statusCode = $e->getStatusCode();

                $message = match ($statusCode) {
                    403 => __('Access denied'),
                    500 => __('Internal server error'),
                    503 => __('Service unavailable'),
                    default => $e->getMessage() ?: __('An error occurred')
                };

                return $createApiErrorResponse($statusCode, $message);
            }
        });

        $exceptions->renderable(function (AuthorizationException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $message = $e->getMessage() ?: __('This action is unauthorized');

                return $createApiErrorResponse(403, $message);
            }
        });

        $exceptions->renderable(function (ThrottleRequestsException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
                $message = __('Too many requests. Please try again later.');

                $headers = [];
                if ($retryAfter) {
                    $headers['Retry-After'] = $retryAfter;
                    $message .= ' ' . __('Retry after :seconds seconds.', ['seconds' => $retryAfter]);
                }

                return $createApiErrorResponse(
                    Response::HTTP_TOO_MANY_REQUESTS,
                    $message,
                    null,
                    $headers
                );
            }
        });

        $exceptions->renderable(function (ValidationException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $errors = $e->errors();
                $firstError = array_values($errors)[0][0] ?? __('Validation failed');

                return $createApiErrorResponse(
                    $e->status,
                    $firstError,
                    [
                        'errors' => $errors,
                        'failed_rules' => array_keys($errors),
                    ]
                );
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*')) {
                $message = $e->getMessage() ?: __('Authentication required');

                return $createApiErrorResponse(Response::HTTP_UNAUTHORIZED, $message);
            }
        });

        $exceptions->renderable(function (Throwable $e, $request) use ($createApiErrorResponse) {
            if ($request->is('api/*') && ! app()->hasDebugModeEnabled()) {
                Log::error('Unhandled API exception', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'request_url' => $request->fullUrl(),
                    'request_method' => $request->method(),
                    'request_ip' => $request->ip(),
                ]);

                return $createApiErrorResponse(
                    500,
                    __('An unexpected error occurred. Please try again later.')
                );
            }
        });

        $exceptions->reportable(function (Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });
    })
    ->create();
