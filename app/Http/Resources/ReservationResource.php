<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Reservation $resource
 */
final class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'order_line_id' => $this->resource->order_line_id,
            'product_id' => $this->resource->product_id,
            'warehouse_id' => $this->resource->warehouse_id,
            'quantity' => $this->resource->quantity,
            'quantity_picked' => $this->resource->quantity_picked,
            'quantity_packed' => $this->resource->quantity_packed,
            'quantity_shipped' => $this->resource->quantity_shipped,
            'quantity_released' => $this->resource->quantity_released,
            'status' => $this->resource->status->value,
            'order_reference' => $this->resource->orderLine?->sales_order_id,
            'expires_at' => $this->resource->expires_at?->toISOString(),
        ];
    }
}
