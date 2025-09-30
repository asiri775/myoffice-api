<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class HttpableException extends RuntimeException
{
    public int $status;
    public ?array $errors;

    public function __construct(string $message, int $status = 400, ?array $errors = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errors = $errors;
    }
}