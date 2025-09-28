<?php

namespace App\Http\Middleware;

use Closure;

class VerifyEmailSignature
{
    public function handle($request, Closure $next)
    {

        $expires = (int) $request->query('expires', 0);
        if ($expires <= time()) {
            abort(403, 'Verification link has expired.');
        }


        $params = $request->query();
        $provided = $params['signature'] ?? '';
        unset($params['signature']);

        $path    = '/' . ltrim($request->getPathInfo(), '/'); 
        $payload = $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $calc    = hash_hmac('sha256', $payload, config('app.key'));

        if (! hash_equals($calc, $provided)) {
            abort(403, 'Invalid verification signature.');
        }

        return $next($request);
    }
}