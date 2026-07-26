<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum MovementType: string
{
    case Adjustment = 'adjustment';
    case Reserve = 'reserve';
    case Release = 'release';
    case Pick = 'pick';
    case Pack = 'pack';
    case Ship = 'ship';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
}
