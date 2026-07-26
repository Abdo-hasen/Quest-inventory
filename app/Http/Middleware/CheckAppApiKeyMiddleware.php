<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Traits\InteractWithResponse;
use Closure;
use Illuminate\Http\Request;

final class CheckAppApiKeyMiddleware
{
    use InteractWithResponse;

    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->header('X-API-KEY');

        if (! $apiKey || ! $this->isValidApiKey($apiKey)) {
            return $this->sendFailedResponse(
                message: 'invalid_request',
                code: 401
            );
        }

        return $next($request);
    }

    private function isValidApiKey(string $apiKey): bool
    {
        $configured = config('app.api_key');

        if (! is_string($configured) || $configured === '') {
            return false;
        }

        return hash_equals($configured, $apiKey);
    }
}
