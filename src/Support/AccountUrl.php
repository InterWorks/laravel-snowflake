<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Support;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;

/**
 * A validated Snowflake account URL. Only https hosts under snowflakecomputing.com or snowflakecomputing.cn pass.
 */
final readonly class AccountUrl
{
    private const PATTERN = '#^https://([a-z0-9][a-z0-9.-]*\.snowflakecomputing\.(?:com|cn))/?$#D';

    private function __construct(private string $host)
    {
    }

    /**
     * The base URL of the SQL API v2.
     */
    public function apiBaseUrl(): string
    {
        return $this->toString() . '/api/v2';
    }

    /**
     * Parses and validates an account URL.
     *
     * The exception message never contains the URL, because it may carry userinfo with a credential.
     *
     * @throws InvalidArgumentException when the URL breaks any rule of the account URL contract.
     */
    public static function fromString(string $url): self
    {
        if (str_contains($url, '\\')) {
            throw new InvalidArgumentException('The Snowflake account URL must not contain a backslash.');
        }

        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException(
                'The Snowflake account URL must not contain whitespace or control characters.'
            );
        }

        if (stripos($url, 'https://') !== 0) {
            throw new InvalidArgumentException('The Snowflake account URL must use the https scheme.');
        }

        if (preg_match('/[@?#]/', $url) === 1) {
            throw new InvalidArgumentException(
                'The Snowflake account URL must not contain userinfo, a query string or a fragment.'
            );
        }

        $normalised = 'https://' . strtolower(substr($url, strlen('https://')));

        if (preg_match(self::PATTERN, $normalised, $matches) !== 1) {
            throw new InvalidArgumentException(
                'The Snowflake account URL must be a bare https host ending in snowflakecomputing.com or '
                . 'snowflakecomputing.cn, without a port or a path.'
            );
        }

        return new self($matches[1]);
    }

    /**
     * The normalised account URL: lowercase host and no trailing slash.
     */
    public function toString(): string
    {
        return 'https://' . $this->host;
    }
}
