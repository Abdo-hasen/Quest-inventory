<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sales_order_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SalesOrder $salesOrder
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 * @property-read ?Reservation $reservation
 */
final class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'order_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sales_order_id',
        'product_id',
        'warehouse_id',
        'quantity',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sales_order_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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
     * @return HasOne<Reservation, $this>
     */
    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }
}
