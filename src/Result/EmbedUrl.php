<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Result;

use Closure;
use InterWorks\SnowflakeApi\Auth\Concealed;
use SensitiveParameter;

/**
 * A single-use URL that loads a Streamlit in Snowflake app in an iframe. The authorization code it carries is a bearer
 * credential: it is valid for minutes, works once, and lets whoever opens it see the app as the identity that minted
 * it. It is therefore held like a token, in a masked closure that dumps, casts, JSON and stack traces do not reveal,
 * and, like a token, it refuses serialisation (a closure cannot be serialised). Hand it to the browser that requested
 * it and nowhere else: do not log, cache or store it.
 */
final readonly class EmbedUrl
{
    private Closure $reveal;

    public function __construct(#[SensitiveParameter] string $url)
    {
        $this->reveal = Concealed::wrap($url);
    }

    /**
     * Hides the URL from var_dump() and print_r().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['url' => '***'];
    }

    /**
     * The URL to set as the iframe's src.
     */
    public function url(): string
    {
        return ($this->reveal)();
    }
}
