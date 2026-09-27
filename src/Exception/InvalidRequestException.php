<?php

declare(strict_types=1);

namespace TrueUp\Exception;

/** 400, 413, 415, 422: the request or the files need fixing. */
class InvalidRequestException extends TrueUpException
{
}
