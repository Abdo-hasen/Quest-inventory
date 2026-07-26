<?php

declare(strict_types=1);

namespace App\Core\Helpers\Shipping;

use App\Core\Enums\ShipmentAttemptStatus;
use App\Models\Shipment;

interface ShippingProviderInterface
{
    /**
     * @return array{
     *     status: ShipmentAttemptStatus,
     *     quantity_shipped: int,
     *     raw: array<string, mixed>
     * }
     */
    public function ship(Shipment $shipment): array;
}
