<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Webhook;

use App\Core\Services\Shipment\ShipmentService;
use App\Core\Traits\InteractWithResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhook\ShippingWebhookRequest;
use App\Models\ProcessedWebhookEvent;
use App\Models\Shipment;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ShippingWebhookController extends Controller
{
    use InteractWithResponse;

    public function __construct(
        private readonly ShipmentService $shipmentService
    ) {}

    public function handle(ShippingWebhookRequest $request): JsonResponse
    {
        $eventId = (string) $request->validated('event_id');

        try {
            DB::transaction(function () use ($request, $eventId): void {
                ProcessedWebhookEvent::create(['event_id' => $eventId]);

                /** @var Shipment $shipment */
                $shipment = Shipment::query()->findOrFail((int) $request->validated('shipment_id'));

                $status = (string) $request->validated('status');
                match ($status) {
                    'success' => $this->shipmentService->confirmShipment($shipment, (int) $request->validated('quantity_shipped')),
                    'permanent_failure' => $this->shipmentService->markFailed($shipment, 'Webhook reported permanent failure'),
                    'timeout' => $this->shipmentService->handleTimeout($shipment, ['source' => 'webhook']),
                    default => null,
                };
            });

            return $this->sendSuccessResponse(
                data: [],
                message: __('Webhook processed'),
                code: 200
            );
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return $this->sendSuccessResponse(
                    data: [],
                    message: __('Event already processed'),
                    code: 200
                );
            }

            throw $e;
        }
    }
}
