<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Auth;

use Closure;
use SensitiveParameter;

/**
 * Wraps a secret in a closure that holds it XOR-masked with a random one-time key.
 *
 * A dump of the closure (Symfony VarDumper, `dd()`, an error page, `(array)` casts, Reflection) shows only masked
 * bytes. This guards against accidental disclosure, not against code that already runs inside your process.
 */
final class Concealed
{
    private function __construct()
    {
    }

    /**
     * @return Closure(): string returns the original secret.
     */
    public static function wrap(#[SensitiveParameter] string $secret): Closure
    {
        if ($secret === '') {
            return static fn (): string => '';
        }

        $key = random_bytes(strlen($secret));
        $masked = $secret ^ $key;

        return static fn (): string => $masked ^ $key;
    }
}
