<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Order;

use App\Core\Services\Order\OrderService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Resources\SalesOrderResource;
use Illuminate\Http\JsonResponse;

final class OrderController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function store(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create(
            $request->validated(),
            (int) $request->user()->id
        );

        return $this->sendSuccessResponse(
            data: new SalesOrderResource($order),
            message: __('Order created'),
            code: 201
        );
    }
}
