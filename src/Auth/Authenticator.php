<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Auth;

/**
 * Supplies the bearer credential for one Snowflake request. Implementations must never log the token.
 */
interface Authenticator
{
    /**
     * The token type: the value of the X-Snowflake-Authorization-Token-Type header.
     */
    public function tokenType(): string;

    /**
     * The bearer credential. Called once per request. The package never caches or logs it.
     */
    public function token(): string;
}
