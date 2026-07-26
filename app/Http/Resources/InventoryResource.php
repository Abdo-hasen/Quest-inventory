<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Inventory $resource
 */
final class InventoryResource extends JsonResource
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
            'quantity_available' => $this->resource->quantity_available,
            'quantity_reserved' => $this->resource->quantity_reserved,
            'quantity_picked' => $this->resource->quantity_picked,
            'quantity_packed' => $this->resource->quantity_packed,
            'quantity_shipped' => $this->resource->quantity_shipped,
        ];
    }
}
