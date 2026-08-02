<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $userId = (int) $request->user()?->id;
        $cacheKey = "idempotency:{$userId}:{$key}";

        /** @var array{response_code: int, response_body: mixed}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['response_code'], $cached['response_body'])) {
            return response()->json($cached['response_body'], (int) $cached['response_code']);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500 && $response instanceof JsonResponse) {
            /** @var array<string, mixed> $body */
            $body = $response->getData(true);
            $statusCode = $response->getStatusCode();

            Cache::put($cacheKey, [
                'response_code' => $statusCode,
                'response_body' => $body,
            ], now()->addHours(24));
        }

        return $response;
    }
}
