<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_line_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $quantity
 * @property int $quantity_picked
 * @property int $quantity_packed
 * @property int $quantity_shipped
 * @property int $quantity_released
 * @property ReservationStatus $status
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read OrderLine $orderLine
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 */
final class Reservation extends Model
{
    use HasFactory;

    protected $table = 'reservations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_line_id',
        'product_id',
        'warehouse_id',
        'quantity',
        'quantity_picked',
        'quantity_packed',
        'quantity_shipped',
        'quantity_released',
        'status',
        'expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_line_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'quantity' => 'integer',
            'quantity_picked' => 'integer',
            'quantity_packed' => 'integer',
            'quantity_shipped' => 'integer',
            'quantity_released' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<ReservationHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(ReservationHistory::class);
    }
}
