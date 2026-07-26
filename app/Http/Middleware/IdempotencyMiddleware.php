<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        /** @var IdempotencyKey|null $existing */
        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null && $existing->created_at->isAfter(now()->subHours(24))) {
            return response()->json($existing->response_body, $existing->response_code);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500 && $response instanceof JsonResponse) {
            /** @var array<string, mixed> $body */
            $body = $response->getData(true);
            $statusCode = $response->getStatusCode();

            try {
                IdempotencyKey::create([
                    'key' => $key,
                    'user_id' => $userId,
                    'response_code' => $statusCode,
                    'response_body' => $body,
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                // If a concurrent request inserted the key first, attempt to return the cached response
                $existing = IdempotencyKey::query()
                    ->where('key', $key)
                    ->where('user_id', $userId)
                    ->first();

                if ($existing !== null) {
                    return response()->json($existing->response_body, $existing->response_code);
                }
            }
        }

        return $response;
    }
}
