<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

/**
 * Snowflake answered 429. The package never retries; the consumer decides what to do with the delay.
 */
final class RateLimitedException extends SnowflakeException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?string $snowflakeCode = null,
        ?string $statementHandle = null,
    ) {
        parent::__construct($message, $httpStatus, $snowflakeCode, $statementHandle);
    }
}
