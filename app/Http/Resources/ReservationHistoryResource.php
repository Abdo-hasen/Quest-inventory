<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReservationHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ReservationHistory $resource
 */
final class ReservationHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from_status' => $this->resource->from_status?->value,
            'to_status' => $this->resource->to_status->value,
            'quantity_affected' => $this->resource->quantity_affected,
            'actor' => $this->resource->actor?->name ?? __('System'),
            'timestamp' => $this->resource->created_at?->toISOString(),
        ];
    }
}
