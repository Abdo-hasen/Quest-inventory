<?php

declare(strict_types=1);

use App\Core\Enums\ShipmentAttemptStatus;
use App\Core\Helpers\Shipping\MockShippingProvider;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    putenv('MOCK_SHIPPING_SCENARIO');
    unset($_ENV['MOCK_SHIPPING_SCENARIO']);
    config(['services.mock_shipping_scenario' => null]);
});

test('MOCK_SHIPPING_SCENARIO=success always succeeds', function () {
    config(['services.mock_shipping_scenario' => 'success']);

    $provider = new MockShippingProvider;
    $shipment = Shipment::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $result = $provider->ship($shipment);
        expect($result['status'])->toBe(ShipmentAttemptStatus::Success);
    }
});

test('MOCK_SHIPPING_SCENARIO=permanent_failure always fails', function () {
    config(['services.mock_shipping_scenario' => 'permanent_failure']);

    $provider = new MockShippingProvider;
    $shipment = Shipment::factory()->create();

    $result = $provider->ship($shipment);
    expect($result['status'])->toBe(ShipmentAttemptStatus::PermanentFailure);
});

test('forceScenario constructor param overrides env', function () {
    config(['services.mock_shipping_scenario' => 'permanent_failure']);

    $provider = new MockShippingProvider('success');
    $shipment = Shipment::factory()->create();

    $result = $provider->ship($shipment);
    expect($result['status'])->toBe(ShipmentAttemptStatus::Success);
});
