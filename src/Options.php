<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;

/**
 * Client options. Every value has a safe default, so the object is optional.
 */
final readonly class Options
{
    /**
     * @param int         $httpTimeoutBufferSeconds seconds added to the statement timeout for the HTTP client timeout
     * @param int         $connectTimeoutSeconds    seconds allowed to open the TCP/TLS connection
     * @param string|null $userAgentSuffix          appended to "interworks-laravel-snowflake/<VERSION>"
     * @param int         $maxResponseBytes         the transfer is aborted once more than this many bytes arrive
     *
     * @throws InvalidArgumentException when a number is out of range or the suffix is not printable ASCII.
     */
    public function __construct(
        public int $httpTimeoutBufferSeconds = 5,
        public int $connectTimeoutSeconds = 10,
        public ?string $userAgentSuffix = null,
        public int $maxResponseBytes = 67_108_864,
    ) {
        if ($httpTimeoutBufferSeconds < 0) {
            throw new InvalidArgumentException('The HTTP timeout buffer must be 0 seconds or more.');
        }

        if ($connectTimeoutSeconds < 1) {
            throw new InvalidArgumentException('The connect timeout must be 1 second or more.');
        }

        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException('The maximum response size must be 1 byte or more.');
        }

        if ($userAgentSuffix !== null && preg_match('/^[\x20-\x7e]+$/D', $userAgentSuffix) !== 1) {
            throw new InvalidArgumentException('The user agent suffix must be a non-empty string of printable ASCII.');
        }
    }

    /**
     * Reads the snowflake.* config keys when a Laravel application with a config repository is running.
     *
     * Falls back to the defaults of this class when there is none.
     *
     * @throws InvalidArgumentException when a configured value is out of range.
     */
    public static function fromLaravelConfig(): self
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return new self();
        }

        $config = app('config');

        $suffix = $config->get('snowflake.user_agent_suffix');

        return new self(
            httpTimeoutBufferSeconds: (int) $config->get('snowflake.http_timeout_buffer', 5),
            connectTimeoutSeconds: (int) $config->get('snowflake.connect_timeout', 10),
            userAgentSuffix: is_string($suffix) && $suffix !== '' ? $suffix : null,
            maxResponseBytes: (int) $config->get('snowflake.max_response_bytes', 67_108_864),
        );
    }
}
