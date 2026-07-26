<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Core\Helpers\Pagination\CustomPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

trait InteractWithResponse
{
    public function sendSuccessResponse(array|JsonResource|Collection $data = [], ?string $message = null, ?int $code = 200, ?string $direct = null): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'code' => $code,
            'message' => $message,
            'direct' => $direct,
            'data' => $data,
        ], $code);
    }

    public function sendFailedResponse(?string $message = null, int $code = 400, ?string $direct = null): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'direct' => $direct,
            'data' => null,
        ], $code);
    }

    public function sendPaginatedResponse($resource, int $code = Response::HTTP_OK, ?string $message = null, ?string $direct = null): JsonResponse
    {
        if (is_null($message)) {
            $message = Response::$statusTexts[$code];
        }
        $paginator = new CustomPaginator(
            items: $resource->items(),
            total: $resource->total(),
            perPage: $resource->perPage(),
            currentPage: $resource->currentPage(),
            options: [
                'code' => $code,
                'message' => $message,
                'direct' => $direct,
            ]
        );

        return response()->json($paginator, $code);
    }

    public function sendCustomPaginatedResponse(CustomPaginator $paginator, int $code = Response::HTTP_OK): JsonResponse
    {
        return response()->json($paginator->toArray(), $code);
    }
}
