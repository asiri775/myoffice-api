<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

trait ApiResponse
{
    protected function respond(
        string $status,
        int $code,
        ?string $message = null,
        $data = null,
        $errors = null,
        array $meta = []
    ): JsonResponse {
        // Ensure a trace id even without middleware
        $traceId = request()->attributes->get('trace_id') ?? (string) Str::uuid();

        // Normalize errors into a plain array and drop if empty
        $errors = $this->normalizeErrors($errors);

        $payload = [
            'status'   => $status,
            'code'     => $code,
            'trace_id' => $traceId,
        ];

        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }
        // Always include data; callers may purposely send []
        $payload['data'] = $data;

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()
            ->json($payload, $code)
            ->header('X-Trace-Id', $traceId);
    }

    /* ========= Success helpers ========= */

    protected function ok($data = null, ?string $message = 'OK', array $meta = []): JsonResponse
    { return $this->respond('success', 200, $message, $data, null, $meta); }

    protected function created($data = null, ?string $message = 'Created'): JsonResponse
    { return $this->respond('success', 201, $message, $data); }

    protected function updated($data = null, ?string $message = 'Updated'): JsonResponse
    { return $this->respond('success', 200, $message, $data); }

    protected function accepted($data = null, ?string $message = 'Accepted'): JsonResponse
    { return $this->respond('success', 202, $message, $data); }

    protected function noContent(): JsonResponse
    {
        $traceId = request()->attributes->get('trace_id') ?? (string) Str::uuid();
        return response()->json(null, 204)->header('X-Trace-Id', $traceId);
    }

    /* ========= Client error helpers ========= */

    protected function fail($errors, ?string $message = 'Validation error', int $code = 422): JsonResponse
    { return $this->respond('fail', $code, $message, null, $errors); }

    protected function badRequest(?string $message = 'Bad request', $errors = null): JsonResponse
    { return $this->respond('error', 400, $message, null, $errors); }

    protected function unauthorized(?string $message = 'Unauthorized'): JsonResponse
    { return $this->respond('error', 401, $message); }

    protected function forbidden(?string $message = 'Forbidden'): JsonResponse
    { return $this->respond('error', 403, $message); }

    protected function notFound(?string $message = 'Not found'): JsonResponse
    { return $this->respond('error', 404, $message); }

    /* ========= Server error helpers ========= */

    protected function serverError(?string $message = 'Server error', $errors = null, int $code = 500): JsonResponse
    { return $this->respond('error', $code, $message, null, $errors); }

    /* ========= Pagination helpers ========= */

    protected function withPaginationMeta($paginator, array $extra = []): array
    {
        if ($paginator instanceof LengthAwarePaginator) {
            $meta = [
                'page'       => $paginator->currentPage(),
                'per_page'   => $paginator->perPage(),
                'total'      => $paginator->total(),
                'last_page'  => $paginator->lastPage(),
                'has_more'   => $paginator->hasMorePages(),
                'next_page'  => $paginator->currentPage() < $paginator->lastPage()
                                ? $paginator->currentPage() + 1 : null,
                'prev_page'  => $paginator->currentPage() > 1
                                ? $paginator->currentPage() - 1 : null,
            ];
            return $extra ? ($meta + $extra) : $meta;
        }
        return $extra;
    }

    /**
     * Convenience: respond 200 with items and auto meta from paginator.
     * Example: return $this->okPaginated($paginator, 'Spaces fetched');
     */
    protected function okPaginated(LengthAwarePaginator $paginator, ?string $message = 'OK'): JsonResponse
    {
        return $this->ok($paginator->items(), $message, $this->withPaginationMeta($paginator));
    }

    /* ========= Internals ========= */

    private function normalizeErrors($errors): array
    {
        if ($errors instanceof MessageBag) {
            return $errors->toArray();
        }
        // Laravel Validator::errors() returns MessageBag; Validator::errors()->toArray() returns array
        if (is_object($errors) && method_exists($errors, 'toArray')) {
            return (array) $errors->toArray();
        }
        if (is_array($errors)) {
            return $errors;
        }
        // scalar error → wrap
        if (is_string($errors) && $errors !== '') {
            return ['_error' => [$errors]];
        }
        return [];
    }
}