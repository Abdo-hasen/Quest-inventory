<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reservation_id
 * @property ?ReservationStatus $from_status
 * @property ReservationStatus $to_status
 * @property ?int $quantity_affected
 * @property ?int $actor_id
 * @property ?string $notes
 * @property Carbon $created_at
 * @property-read Reservation $reservation
 * @property-read User $actor
 */
final class ReservationHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'reservation_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_id',
        'from_status',
        'to_status',
        'quantity_affected',
        'actor_id',
        'notes',
        'created_at',
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
            'from_status' => ReservationStatus::class,
            'to_status' => ReservationStatus::class,
            'quantity_affected' => 'integer',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withDefault(['name' => __('System')]);
    }
}
