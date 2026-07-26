<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Reservation;

use App\Core\Services\Reservation\ReservationService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\PartialCancelRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReservationController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly ReservationService $reservationService
    ) {}

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
}
