<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $quantity_available
 * @property int $quantity_reserved
 * @property int $quantity_picked
 * @property int $quantity_packed
 * @property int $quantity_shipped
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Product $product
 * @property-read Warehouse $warehouse
 */
final class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    protected $table = 'inventory';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity_available',
        'quantity_reserved',
        'quantity_picked',
        'quantity_packed',
        'quantity_shipped',
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
            'quantity_available' => 'integer',
            'quantity_reserved' => 'integer',
            'quantity_picked' => 'integer',
            'quantity_packed' => 'integer',
            'quantity_shipped' => 'integer',
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
}
