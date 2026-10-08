<?php

namespace App\Exceptions;

use RuntimeException;

final class FacebookPageConnectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422,
        public readonly ?int $metaErrorCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
