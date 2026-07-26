<?php

declare(strict_types=1);

namespace App\Core\Services\Warehouse;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class WarehouseService
{
    /**
     * @return Collection<int, Warehouse>
     */
    public function index(): Collection
    {
        return Warehouse::all();
    }

    public function findById(int $id): Warehouse
    {
        return Warehouse::findOrFail($id);
    }

    /**
     * @param  array{name: string, code: string, address?: ?string, is_active?: bool}  $data
     */
    public function store(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $warehouse = new Warehouse;
            $warehouse->name = $data['name'];
            $warehouse->code = $data['code'];
            $warehouse->address = $data['address'] ?? null;
            $warehouse->is_active = $data['is_active'] ?? true;
            $warehouse->save();

            return $warehouse;
        });
    }

    /**
     * @param  array{name?: string, address?: ?string, is_active?: bool}  $data
     */
    public function update(array $data, int $id): Warehouse
    {
        return DB::transaction(function () use ($data, $id): Warehouse {
            $warehouse = $this->findById($id);

            if (array_key_exists('name', $data)) {
                $warehouse->name = $data['name'];
            }

            if (array_key_exists('address', $data)) {
                $warehouse->address = $data['address'];
            }

            if (array_key_exists('is_active', $data)) {
                $warehouse->is_active = (bool) $data['is_active'];
            }

            $warehouse->save();

            return $warehouse;
        });
    }
}
