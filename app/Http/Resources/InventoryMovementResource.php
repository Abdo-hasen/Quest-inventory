<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read InventoryMovement $resource
 */
final class InventoryMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'warehouse_id' => $this->resource->warehouse_id,
            'type' => $this->resource->type->value,
            'quantity_delta' => $this->resource->quantity_delta,
            'reason' => $this->resource->reason,
            'actor_id' => $this->resource->actor_id,
            'related_order_id' => $this->resource->related_order_id,
            'related_reservation_id' => $this->resource->related_reservation_id,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
