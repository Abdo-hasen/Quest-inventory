<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\ShipmentAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shipment_id
 * @property int $attempt_number
 * @property ShipmentAttemptStatus $status
 * @property array<string, mixed>|null $provider_response
 * @property Carbon $created_at
 * @property-read Shipment $shipment
 */
final class ShipmentAttempt extends Model
{
    use HasFactory;

    protected $table = 'shipment_attempts';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shipment_id',
        'attempt_number',
        'status',
        'provider_response',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'attempt_number' => 'integer',
            'status' => ShipmentAttemptStatus::class,
            'provider_response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
