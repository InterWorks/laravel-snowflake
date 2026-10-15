<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

/**
 * Snowflake answered 401 or 403: the credential was rejected or lacks the privilege.
 */
final class AuthenticationException extends SnowflakeException
{
}
