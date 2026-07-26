<?php

declare(strict_types=1);

namespace App\Core\Services\Product;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductService
{
    /**
     * @return LengthAwarePaginator<Product>
     */
    public function index(): LengthAwarePaginator
    {
        return Product::paginate(15);
    }

    public function findById(int $id): Product
    {
        return Product::findOrFail($id);
    }

    /**
     * @param  array{name: string, sku: string, description?: ?string}  $data
     */
    public function store(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $product = new Product;
            $product->name = $data['name'];
            $product->sku = $data['sku'];
            $product->description = $data['description'] ?? null;
            $product->save();

            return $product;
        });
    }

    /**
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function update(array $data, int $id): Product
    {
        return DB::transaction(function () use ($data, $id): Product {
            $product = $this->findById($id);

            if (array_key_exists('name', $data)) {
                $product->name = $data['name'];
            }

            if (array_key_exists('description', $data)) {
                $product->description = $data['description'];
            }

            $product->save();

            return $product;
        });
    }

    public function destroy(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $product = $this->findById($id);

            if (DB::table('inventory')->where('product_id', $id)->exists()) {
                throw ValidationException::withMessages([
                    'product' => __('Cannot delete a product with active inventory'),
                ]);
            }

            return (bool) $product->delete();
        });
    }
}
