<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Reservation;

use App\Core\Enums\ReservationStatus;
use App\Core\Services\Reservation\ReservationService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\PackRequest;
use App\Http\Requests\Reservation\PartialCancelRequest;
use App\Http\Requests\Reservation\PickRequest;
use App\Http\Resources\ReservationHistoryResource;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReservationController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly ReservationService $reservationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $warehouseId = $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null;
        $productId = $request->query('product_id') !== null ? (int) $request->query('product_id') : null;

        $paginator = Reservation::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status), fn ($q) => $q->whereNotIn('status', [
                ReservationStatus::Released->value,
                ReservationStatus::Expired->value,
                ReservationStatus::Fulfilled->value,
            ]))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->with(['orderLine.salesOrder'])
            ->paginate(15);

        return $this->sendPaginatedResponse(ReservationResource::collection($paginator));
    }

    public function release(Request $request, int $reservation): JsonResponse
    {
        try {
            $reservationModel = $this->reservationService->release(
                $reservation,
                (int) $request->user()->id
            );

            return $this->sendSuccessResponse(
                data: [
                    'reservation_id' => $reservationModel->id,
                    'status' => $reservationModel->status->value,
                ],
                message: __('Reservation released'),
                code: 200
            );
        } catch (DomainException $e) {
            return $this->sendFailedResponse(
                message: $e->getMessage(),
                code: 409
            );
        }
    }

    public function partialCancel(PartialCancelRequest $request, int $order, int $line): JsonResponse
    {
        try {
            $orderLine = $this->reservationService->partialCancel(
                $order,
                $line,
                (int) $request->validated('quantity'),
                (int) $request->user()->id
            );

            return $this->sendSuccessResponse(
                data: [
                    'order_line_id' => $orderLine->id,
                    'quantity' => $orderLine->quantity,
                    'reservation_status' => $orderLine->reservation?->status->value,
                ],
                message: __('Order line updated'),
                code: 200
            );
        } catch (DomainException $e) {
            return $this->sendFailedResponse(
                message: $e->getMessage(),
                code: 409
            );
        }
    }

    public function pick(PickRequest $request, int $reservation): JsonResponse
    {
        try {
            $reservationModel = $this->reservationService->pick(
                $reservation,
                $request->validated('quantity') !== null ? (int) $request->validated('quantity') : null,
                (int) $request->user()->id
            );

            return $this->sendSuccessResponse(
                data: [
                    'reservation_id' => $reservationModel->id,
                    'quantity_picked' => $reservationModel->quantity_picked,
                    'quantity_reserved' => $reservationModel->quantity - $reservationModel->quantity_picked,
                    'status' => $reservationModel->status->value,
                ],
                message: __('Stock marked as picked'),
                code: 200
            );
        } catch (DomainException $e) {
            return $this->sendFailedResponse(
                message: $e->getMessage(),
                code: 409
            );
        }
    }

    public function pack(PackRequest $request, int $reservation): JsonResponse
    {
        try {
            $reservationModel = $this->reservationService->pack(
                $reservation,
                (int) $request->validated('quantity'),
                (int) $request->user()->id
            );

            return $this->sendSuccessResponse(
                data: [
                    'reservation_id' => $reservationModel->id,
                    'quantity_packed' => $reservationModel->quantity_packed,
                    'quantity_picked' => $reservationModel->quantity_picked - $reservationModel->quantity_packed,
                    'status' => $reservationModel->status->value,
                ],
                message: __('Stock marked as packed'),
                code: 200
            );
        } catch (DomainException $e) {
            return $this->sendFailedResponse(
                message: $e->getMessage(),
                code: 409
            );
        }
    }

    public function history(int $reservation): JsonResponse
    {
        $reservationModel = Reservation::query()->findOrFail($reservation);
        $history = $reservationModel->history()
            ->with('actor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $this->sendSuccessResponse(
            data: [
                'reservation_id' => $reservationModel->id,
                'history' => ReservationHistoryResource::collection($history),
            ],
            code: 200
        );
    }
}
