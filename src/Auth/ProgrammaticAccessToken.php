<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Auth;

use Closure;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use SensitiveParameter;

/**
 * A Snowflake Programmatic Access Token (PAT).
 *
 * The token lives only inside a closure, masked with a random key, so var_export(), print_r(), var_dump(),
 * VarDumper (dd()), serialize() and stack traces do not reveal it.
 */
final readonly class ProgrammaticAccessToken implements Authenticator
{
    private Closure $reveal;

    /**
     * @throws InvalidArgumentException when the token is empty or contains anything but visible ASCII characters.
     */
    public function __construct(#[SensitiveParameter] string $token)
    {
        if (preg_match('/^[\x21-\x7e]+$/D', $token) !== 1) {
            throw new InvalidArgumentException(
                'The programmatic access token must be a non-empty string of visible ASCII.'
            );
        }

        $this->reveal = Concealed::wrap($token);
    }

    /**
     * Hides the token from var_dump() and print_r().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '***'];
    }

    /**
     * The raw token. Called once per request.
     */
    public function token(): string
    {
        return ($this->reveal)();
    }

    /**
     * The X-Snowflake-Authorization-Token-Type header value.
     */
    public function tokenType(): string
    {
        return 'PROGRAMMATIC_ACCESS_TOKEN';
    }
}
