<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Enums\ReservationStatus;
use App\Core\Services\Reservation\ReservationService;
use App\Models\Reservation;
use Illuminate\Console\Command;

final class ExpireReservationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reservations:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire stale open reservations';

    /**
     * Execute the console command.
     */
    public function handle(ReservationService $reservationService): int
    {
        $count = 0;

        Reservation::query()
            ->where('status', ReservationStatus::Open->value)
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($reservations) use ($reservationService, &$count) {
                foreach ($reservations as $reservation) {
                    if ($reservationService->expire((int) $reservation->id)) {
                        $count++;
                    }
                }
            });

        $this->info("Expired {$count} stale reservations.");

        return 0;
    }
}
