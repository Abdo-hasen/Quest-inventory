<?php

declare(strict_types=1);

namespace App\Core\Helpers\Pagination;

use Illuminate\Pagination\LengthAwarePaginator;

final class CustomPaginator extends LengthAwarePaginator
{
    public function __construct($items, $total, $perPage, $currentPage = null, array $options = [])
    {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }

    public function toArray(): array
    {
        $payload = [
            'success' => true,
            'code' => $this->options['code'],
            'message' => $this->options['message'],
            'direct' => $this->options['direct'],
            'pageNumber' => $this->currentPage() - 1,
            'totalPages' => ceil($this->total / $this->perPage()),
            'totalDataCount' => $this->total(),
            'data' => $this->items->toArray(),
        ];

        $append = $this->options['append'] ?? null;

        if (is_array($append) && $append !== []) {
            return array_merge($payload, $append);
        }

        return $payload;
    }
}
