<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

/**
 * Snowflake answered 202: the statement outlived the synchronous wait. Polling arrives in a later release.
 */
final class StatementStillRunningException extends SnowflakeException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $snowflakeCode = null,
        ?string $statementHandle = null,
        public readonly ?string $statementStatusUrl = null,
    ) {
        parent::__construct($message, $httpStatus, $snowflakeCode, $statementHandle);
    }
}
