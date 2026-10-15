<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Support;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;

/**
 * Validates unquoted Snowflake identifiers. Quoted identifiers are not supported.
 */
final class Identifier
{
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_$]{0,254}$/D';

    /**
     * Returns the value unchanged when it is a valid unquoted identifier.
     *
     * @throws InvalidArgumentException when the value is not a valid unquoted identifier.
     */
    public static function assertUnquoted(string $value, string $field): string
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('The %s must be an unquoted Snowflake identifier of 1 to 255 characters.', $field)
            );
        }

        return $value;
    }
}
