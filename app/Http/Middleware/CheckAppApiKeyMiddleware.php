<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Core\Traits\InteractWithResponse;

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
        return hash_equals(config('app.api_key'), $apiKey);
    }
}
