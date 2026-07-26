<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reservation_id
 * @property int $quantity
 * @property ShipmentStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Reservation $reservation
 */
final class Shipment extends Model
{
    use HasFactory;

    protected $table = 'shipments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'quantity',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'quantity' => 'integer',
            'status' => ShipmentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return HasMany<ShipmentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ShipmentAttempt::class);
    }
}
