<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Support;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;

/**
 * A validated origin of the page that frames an embedded Streamlit in Snowflake app: `https://host` or
 * `https://host:port`, and `http://localhost[:port]` for development, which is what Snowflake accepts in
 * `STREAMLIT_EMBEDDING_CONTROLS` and in the `parent_origin` of an embed URL request.
 */
final readonly class ParentOrigin
{
    private const HOST = '(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

    private const PATTERN = '#^(https|http)://(' . self::HOST . ')(?::([0-9]{1,5}))?/?$#D';

    private function __construct(private string $origin)
    {
    }

    /**
     * Parses and validates an origin. Scheme and host are lowercased, a trailing slash and the scheme's default port
     * are dropped, so the result matches the origin a browser reports.
     *
     * The exception message never contains the value, because a malformed URL may carry userinfo with a credential.
     *
     * @throws InvalidArgumentException when the value is not an absolute https origin, or http on localhost.
     */
    public static function fromString(string $origin): self
    {
        if (preg_match('/[\x00-\x20\x7f\\\\@?#]/', $origin) === 1) {
            throw new InvalidArgumentException(
                'The parent origin must not contain whitespace, control characters, a backslash, userinfo, a query '
                . 'string or a fragment.'
            );
        }

        if (preg_match(self::PATTERN, strtolower($origin), $matches) !== 1) {
            throw new InvalidArgumentException(
                'The parent origin must be an absolute https origin of the form https://host or https://host:port, '
                . 'without a path.'
            );
        }

        $scheme = $matches[1];
        $host = $matches[2];
        $port = $matches[3] ?? '';

        if ($scheme === 'http' && $host !== 'localhost') {
            throw new InvalidArgumentException('The parent origin must use https; http is allowed only for localhost.');
        }

        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            throw new InvalidArgumentException('The parent origin port must be between 1 and 65535.');
        }

        $defaultPort = $scheme === 'https' ? '443' : '80';
        $suffix = $port === '' || ltrim($port, '0') === $defaultPort ? '' : ':' . ltrim($port, '0');

        return new self($scheme . '://' . $host . $suffix);
    }

    /**
     * The normalised origin, as sent in `parent_origin`.
     */
    public function toString(): string
    {
        return $this->origin;
    }
}
