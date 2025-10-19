<?php
declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    use ApiResponse;

    public function render($request, Throwable $e)
    {
        // Already a JSON response
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        // 401 from guards/providers (expired/invalid token, etc.)
        if ($e instanceof AuthenticationException || $e instanceof UnauthorizedHttpException) {
            return $this->unauthorized('Invalid or missing credentials');
        }

        // 422 validation
        if ($e instanceof ValidationException) {
            return $this->fail($e->errors(), 'Validation error', 422);
        }

        // 404
        if ($e instanceof ModelNotFoundException) {
            return $this->notFound('Resource not found');
        }

        // Custom httpable errors (if you use such a class)
        if ($e instanceof HttpableException) {
            return $this->respond(
                $e->status >= 500 ? 'error' : ($e->status >= 400 ? 'error' : 'success'),
                $e->status,
                $e->getMessage(),
                null,
                $e->errors
            );
        }

        // HTTP exceptions (abort(403) etc.)
        if ($e instanceof HttpException) {
            return $this->respond('error', $e->getStatusCode(), $e->getMessage() ?: 'HTTP error');
        }

        // DB errors (hide details in prod)
        if ($e instanceof QueryException) {
            $msg = app()->environment('production') ? 'Database query failed' : $e->getMessage();
            return $this->serverError($msg);
        }

        // Fallback 500
        $msg = app()->environment('production') ? 'Server error' : $e->getMessage();
        return $this->serverError($msg);
    }
}
