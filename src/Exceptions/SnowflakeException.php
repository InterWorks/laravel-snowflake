<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

use RuntimeException;

/**
 * Base class of every exception the package throws for a Snowflake call.
 *
 * No previous exception is ever chained: Guzzle and Saloon exceptions carry the request, including the Authorization
 * header. The message is passed through a Bearer-token and JWT redaction as a second line of defence.
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
     * Replaces any Bearer credential with "Bearer ***" and any JWT-shaped string with "eyJ***".
     */
    public static function redact(string $message): string
    {
        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $message);
        $redacted = $redacted === null ? null : preg_replace('/eyJ[\w-]+\.[\w-]+(?:\.[\w-]*)?/', 'eyJ***', $redacted);

        return $redacted ?? 'The message could not be sanitised.';
    }
}
