<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Services\Shipment\ShipmentService;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CheckShipmentStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Calculate backoff delay with random jitter (80% - 120% of base delay)
     * to prevent Thundering Herd on external carrier API.
     */
    public function backoff(): int
    {
        $baseBackoffs = [60, 300, 900, 3600];
        $attemptIndex = max(0, $this->attempts() - 1);
        $baseDelay = $baseBackoffs[min($attemptIndex, count($baseBackoffs) - 1)];

        return random_int((int) ($baseDelay * 0.8), (int) ($baseDelay * 1.2));
    }

    public function __construct(
        public readonly Shipment $shipment
    ) {}

    public function handle(ShipmentService $service): void
    {
        $service->processShipment($this->shipment);
    }

    public function failed(?Throwable $e): void
    {
        /** @var ShipmentService $service */
        $service = app(ShipmentService::class);
        $service->markFailed($this->shipment, $e?->getMessage());
    }
}
