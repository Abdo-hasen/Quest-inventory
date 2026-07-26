<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum OrderStatus: string
{
    case Open = 'open';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
