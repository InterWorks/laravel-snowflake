<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Http;

use InterWorks\SnowflakeApi\Auth\Authenticator;
use InterWorks\SnowflakeApi\Options;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\Support\AccountUrl;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * The Saloon connector for one Snowflake account and one credential. It never retries and never logs.
 */
final class SnowflakeConnector extends Connector
{
    public function __construct(
        private readonly AccountUrl $accountUrl,
        private readonly Authenticator $credentials,
        private readonly Options $options,
    ) {
    }

    /**
     * Resolves the bearer token for this request. The package never caches it.
     */
    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->credentials->token());
    }

    /**
     * Only the connect timeout is fixed here; the request sets the total timeout from its statement.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['connect_timeout' => $this->options->connectTimeoutSeconds];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        $userAgent = 'interworks-laravel-snowflake/' . Snowflake::VERSION;

        if ($this->options->userAgentSuffix !== null) {
            $userAgent .= ' ' . $this->options->userAgentSuffix;
        }

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Snowflake-Authorization-Token-Type' => $this->credentials->tokenType(),
            'User-Agent' => $userAgent,
        ];
    }

    /**
     * The SQL API v2 base URL of the account.
     */
    public function resolveBaseUrl(): string
    {
        return $this->accountUrl->apiBaseUrl();
    }
}
