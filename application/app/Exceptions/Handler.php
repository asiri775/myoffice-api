<?php
declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponse;

    public function render($request, Throwable $e)
    {
        // Already JSON response thrown
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        // Validation
        if ($e instanceof ValidationException) {
            return $this->fail($e->errors(), 'Validation error', 422);
        }

        // Auth
        if ($e instanceof AuthenticationException) {
            return $this->unauthorized('Invalid or missing credentials');
        }

        // 404 from Eloquent
        if ($e instanceof ModelNotFoundException) {
            return $this->notFound('Resource not found');
        }

        // Custom "httpable" errors
        if ($e instanceof HttpableException) {
            return $this->respond(
                $e->status >= 500 ? 'error' : ($e->status >= 400 ? 'error' : 'success'),
                $e->status,
                $e->getMessage(),
                null,
                $e->errors
            );
        }

        // HTTP exceptions (e.g., abort(403))
        if ($e instanceof HttpException) {
            return $this->respond('error', $e->getStatusCode(), $e->getMessage() ?: 'HTTP error');
        }

        // DB errors (hide details in production)
        if ($e instanceof QueryException) {
            $msg = app()->environment('production')
                ? 'Database query failed'
                : $e->getMessage();
            return $this->serverError($msg);
        }

        // Fallback 500 (hide stack in production)
        $msg = app()->environment('production') ? 'Server error' : $e->getMessage();
        return $this->serverError($msg);
    }
}