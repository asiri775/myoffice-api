<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;

final class AttachTraceId
{
    public function handle($request, Closure $next)
    {
        $traceId = (string) Str::uuid();
        $request->attributes->set('trace_id', $traceId);
        $response = $next($request);
        return $response->header('X-Trace-Id', $traceId);
    }
}