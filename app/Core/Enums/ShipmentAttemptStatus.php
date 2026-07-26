<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ShipmentAttemptStatus: string
{
    case Success = 'success';
    case PermanentFailure = 'permanent_failure';
    case Timeout = 'timeout';
    case DelayedSuccess = 'delayed_success';
}
