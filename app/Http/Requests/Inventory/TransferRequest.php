<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'from_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('is_active', true),
            ],
            'to_warehouse_id' => [
                'required',
                'integer',
                'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->where('is_active', true),
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product_id' => __('Product'),
            'from_warehouse_id' => __('From warehouse'),
            'to_warehouse_id' => __('To warehouse'),
            'quantity' => __('Quantity'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => __('Source and destination warehouse must differ'),
        ];
    }
}
