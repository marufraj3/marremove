<?php

namespace App\Exceptions;

use RuntimeException;

final class FacebookCommentActionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCategory,
        public readonly bool $retryable = false,
        public readonly ?int $responseCode = null,
        public readonly ?int $metaErrorCode = null,
    ) {
        parent::__construct($message);
    }
}
