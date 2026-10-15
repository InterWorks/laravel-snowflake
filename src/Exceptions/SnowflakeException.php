<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

use RuntimeException;

/**
 * Base class of every exception the package throws for a Snowflake call.
 *
 * No previous exception is ever chained: Guzzle and Saloon exceptions carry the request, including the Authorization
 * header. The message is passed through a Bearer-token redaction as a second line of defence.
 */
class SnowflakeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $snowflakeCode = null,
        public readonly ?string $statementHandle = null,
    ) {
        parent::__construct(self::redact($message));
    }

    /**
     * Replaces any Bearer credential in the text with "Bearer ***".
     */
    public static function redact(string $message): string
    {
        return preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $message) ?? 'The message could not be sanitised.';
    }
}
