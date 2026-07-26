<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Order;

use App\Core\Enums\ReservationStatus;
use App\Core\Enums\UserRole;
use App\Core\Services\Order\OrderService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Resources\SalesOrderResource;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $isAdmin = $request->user()->role === UserRole::Admin;
        $consumed = $request->boolean('consumed');

        $paginator = SalesOrder::query()
            ->when($request->has('consumed') && $consumed, fn ($q) => $q->whereHas('orderLines.reservation', fn ($q2) => $q2->whereNotIn('status', [
                ReservationStatus::Released->value,
                ReservationStatus::Expired->value,
            ])))
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['orderLines.reservation'])
            ->paginate(15);

        return $this->sendPaginatedResponse(SalesOrderResource::collection($paginator));
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $isAdmin = $request->user()->role === UserRole::Admin;

        $salesOrder = SalesOrder::query()
            ->when(! $isAdmin, fn ($q) => $q->where('user_id', $request->user()->id))
            ->with(['orderLines.reservation'])
            ->findOrFail($order);

        return $this->sendSuccessResponse(new SalesOrderResource($salesOrder));
    }

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
