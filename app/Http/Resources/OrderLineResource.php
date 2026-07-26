<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read OrderLine $resource
 */
final class OrderLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_line_id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'warehouse_id' => $this->resource->warehouse_id,
            'quantity' => $this->resource->quantity,
            'reservation_id' => $this->resource->reservation?->id,
            'reservation_status' => $this->resource->reservation?->status?->value,
            'expires_at' => $this->resource->reservation?->expires_at?->toISOString(),
        ];
    }
}
