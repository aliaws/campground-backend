<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shared JSON shape for every paginated index() response — the frontend's
 * lib/utils/pagination.ts::paginationMeta() expects `data` to be
 * {data: T[], current_page, last_page, per_page, total, ...}, not a bare
 * Resource::collection() (which, when nested inside a manually-built
 * response()->json([...]) array rather than returned directly from the
 * controller, serializes to a flat array with no pagination metadata at
 * all — confirmed live: this was silently losing `total`/`current_page`/
 * `last_page` on every endpoint that skipped this and just did
 * `'data' => SomeResource::collection($paginator)`, making the frontend
 * fall back to treating the response as an unpaginated flat list, which
 * broke "Showing X of Y"/page-size switching once a table had more rows
 * than fit on one page). Use this for every new/fixed paginated endpoint
 * instead of re-deriving the same six fields inline.
 */
trait FormatsPaginatedResponse
{
    /**
     * @param  class-string  $resourceClass  A JsonResource class with a static ::collection() method.
     */
    protected function paginatedData(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        return [
            'data' => $resourceClass::collection($paginator),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
        ];
    }
}
