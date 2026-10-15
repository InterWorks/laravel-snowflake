<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Exceptions;

use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * Thrown when a caller passes a value the package refuses to send to Snowflake.
 *
 * Messages describe the rule that failed and never echo the rejected value, because that value may carry a credential.
 */
final class InvalidArgumentException extends BaseInvalidArgumentException
{
}
