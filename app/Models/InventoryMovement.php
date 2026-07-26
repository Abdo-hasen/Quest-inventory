<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property MovementType $type
 * @property int $quantity_delta
 * @property ?string $reason
 * @property ?int $actor_id
 * @property ?int $related_order_id
 * @property ?int $related_reservation_id
 * @property Carbon $created_at
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 * @property-read ?User $actor
 */
final class InventoryMovement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'inventory_movements';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'type',
        'quantity_delta',
        'reason',
        'actor_id',
        'related_order_id',
        'related_reservation_id',
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
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'type' => MovementType::class,
            'quantity_delta' => 'integer',
            'actor_id' => 'integer',
            'related_order_id' => 'integer',
            'related_reservation_id' => 'integer',
            'created_at' => 'datetime',
        ];
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
