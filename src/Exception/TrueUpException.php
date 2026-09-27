<?php

declare(strict_types=1);

namespace TrueUp\Exception;

/** Any error the API returned, or a failure to reach it. getErrorCode() is the API's error code; branch on it. */
class TrueUpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'connection_error',
        public readonly mixed $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
