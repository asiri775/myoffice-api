<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;

class Authenticate
{
    use ApiResponse;

    protected Auth $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    public function handle($request, Closure $next, $guard = null)
    {
        if ($this->auth->guard($guard)->guest()) {
            // Standardized 401 with trace id header
            return $this->unauthorized('Unauthorized');
        }

        return $next($request);
    }
}