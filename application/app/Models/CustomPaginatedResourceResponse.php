<?php

namespace App\Models;
use Illuminate\Http\Resources\Json\PaginatedResourceResponse;

class CustomPaginatedResourceResponse extends PaginatedResourceResponse
{
    protected function paginationLinks($paginated)
    {
        return [
            'prev' => $paginated['prev_page_url'] ?? null,
            'next' => $paginated['next_page_url'] ?? null,
        ];
    }

    protected function meta($paginated)
    {
        $metaData = parent::meta($paginated);
        return [
            'current_page' => $metaData['current_page'] ?? null,
            'total_items' => $metaData['total'] ?? null,
            'per_page' => $metaData['per_page'] ?? null,
            'total_pages' => $metaData['total'] ?? null,
        ];
    }
}