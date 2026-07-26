<?php

declare(strict_types=1);

namespace App\Core\Helpers\Shipping;

use App\Core\Enums\ShipmentAttemptStatus;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

final class MockShippingProvider implements ShippingProviderInterface
{
    public function __construct(private ?string $forceScenario = null) {}

    public function ship(Shipment $shipment): array
    {
        $envScenario = env('MOCK_SHIPPING_SCENARIO');
        $scenario = $this->forceScenario ?? (is_string($envScenario) && $envScenario !== '' ? $envScenario : null);

        if ($scenario === null) {
            $rand = random_int(0, 99);
            if ($rand < 60) {
                $scenario = ShipmentAttemptStatus::Success->value;
            } elseif ($rand < 80) {
                $scenario = ShipmentAttemptStatus::PermanentFailure->value;
            } elseif ($rand < 90) {
                $scenario = ShipmentAttemptStatus::Timeout->value;
            } else {
                $scenario = ShipmentAttemptStatus::DelayedSuccess->value;
            }
        }

        Log::info('MockShippingProvider', [
            'shipment_id' => $shipment->id,
            'outcome' => $scenario,
        ]);

        $status = ShipmentAttemptStatus::tryFrom($scenario) ?? ShipmentAttemptStatus::Success;

        return [
            'status' => $status,
            'quantity_shipped' => $shipment->quantity,
            'raw' => [
                'scenario' => $scenario,
                'shipment_id' => $shipment->id,
            ],
        ];
    }
}
