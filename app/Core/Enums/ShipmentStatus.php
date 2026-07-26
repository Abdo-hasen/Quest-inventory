<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ShipmentStatus: string
{
    case Pending = 'pending';
    case InTransit = 'in_transit';
    case Shipped = 'shipped';
    case Failed = 'failed';
    case Timeout = 'timeout';
}
