<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'lines.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines' => __('Lines'),
            'lines.*.product_id' => __('Product'),
            'lines.*.warehouse_id' => __('Warehouse'),
            'lines.*.quantity' => __('Quantity'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('Lines are required'),
            'lines.min' => __('At least one line is required'),
            'lines.*.product_id.required' => __('Product is required'),
            'lines.*.product_id.exists' => __('Selected product is invalid or inactive'),
            'lines.*.warehouse_id.required' => __('Warehouse is required'),
            'lines.*.warehouse_id.exists' => __('Selected warehouse is invalid or inactive'),
            'lines.*.quantity.required' => __('Quantity is required'),
            'lines.*.quantity.min' => __('Quantity must be at least 1'),
        ];
    }
}
