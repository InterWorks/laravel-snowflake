<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

/**
 * Snowflake answered 422: the statement failed to compile or execute. The message never contains the SQL text.
 */
final class SqlException extends SnowflakeException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $snowflakeCode = null,
        ?string $statementHandle = null,
        public readonly ?string $sqlState = null,
    ) {
        parent::__construct($message, $httpStatus, $snowflakeCode, $statementHandle);
    }
}
