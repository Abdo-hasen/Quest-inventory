<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Enums\ReservationStatus;
use App\Core\Enums\ShipmentStatus;
use App\Jobs\ProcessShipmentJob;
use App\Models\Reservation;
use App\Models\Shipment;
use Illuminate\Console\Command;

final class ProcessShipmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shipments:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process packed reservations by creating shipments and dispatching processing jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        Reservation::query()
            ->where('status', ReservationStatus::Packed->value)
            ->chunkById(100, function ($reservations) use (&$count): void {
                /** @var Reservation $reservation */
                foreach ($reservations as $reservation) {
                    $hasActiveShipment = Shipment::query()
                        ->where('reservation_id', $reservation->id)
                        ->whereIn('status', [
                            ShipmentStatus::Pending->value,
                            ShipmentStatus::InTransit->value,
                            ShipmentStatus::Shipped->value,
                        ])
                        ->exists();

                    if (! $hasActiveShipment) {
                        $shipment = Shipment::create([
                            'reservation_id' => $reservation->id,
                            'quantity' => $reservation->quantity_packed,
                            'status' => ShipmentStatus::Pending,
                        ]);

                        ProcessShipmentJob::dispatch($shipment)->onQueue('default');
                        $count++;
                    }
                }
            });

        $this->info("Dispatched {$count} shipment processing jobs.");

        return 0;
    }
}
