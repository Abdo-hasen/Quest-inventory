<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShippingWebhookRequest extends FormRequest
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
            'event_id' => ['required', 'string'],
            'shipment_id' => ['required', 'integer', Rule::exists('shipments', 'id')],
            'status' => ['required', Rule::in(['success', 'permanent_failure', 'timeout'])],
            'quantity_shipped' => [
                Rule::when($this->input('status') === 'success', ['required', 'integer', 'min:1'], ['nullable', 'integer', 'min:1']),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'event_id' => __('Event ID'),
            'shipment_id' => __('Shipment ID'),
            'status' => __('Status'),
            'quantity_shipped' => __('Quantity Shipped'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.required' => __('The :attribute field is required.'),
            'shipment_id.required' => __('The :attribute field is required.'),
            'status.required' => __('The :attribute field is required.'),
            'quantity_shipped.required' => __('The :attribute field is required.'),
        ];
    }
}
