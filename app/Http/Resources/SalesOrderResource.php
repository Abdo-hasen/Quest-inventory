<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SalesOrder $resource
 */
final class SalesOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'lines' => OrderLineResource::collection($this->resource->orderLines),
        ];
    }
}
