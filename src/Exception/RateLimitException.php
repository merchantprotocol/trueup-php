<?php

declare(strict_types=1);

namespace TrueUp\Exception;

/** 429 rate_limited: slow down. $retryAfter is in seconds. */
class RateLimitException extends TrueUpException
{
    public ?float $retryAfter = null;
}
