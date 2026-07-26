<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Services\Shipment\ShipmentService;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessShipmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60];

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
