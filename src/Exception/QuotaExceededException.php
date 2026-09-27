<?php

declare(strict_types=1);

namespace TrueUp\Exception;

/** 429 quota_exceeded: the team used its plan's allowance this month. Retrying won't help. */
class QuotaExceededException extends TrueUpException
{
}
