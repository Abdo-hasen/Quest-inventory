<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case OrderCreator = 'order_creator';
    case WarehouseOperator = 'warehouse_operator';
}
