<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

/**
 * The request did not complete: connection failure, HTTP timeout, 5xx, an unexpected status or an unreadable body.
 */
final class TransportException extends SnowflakeException
{
}
