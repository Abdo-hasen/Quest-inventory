<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ReservationStatus: string
{
    case Open = 'open';
    case Picked = 'picked';
    case Packed = 'packed';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Released = 'released';
    case Expired = 'expired';
}
