<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Http\Requests;

use InterWorks\SnowflakeApi\Support\ParentOrigin;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /databases/{database}/schemas/{schema}/streamlits/{name}:generate-embed-url. The caller validates the
 * identifiers and the origin. Status handling is explicit in Snowflake::generateStreamlitEmbedUrl(); nothing here
 * throws.
 */
final class GenerateStreamlitEmbedUrlRequest extends Request implements HasBody
{
    use HasJsonBody;

    /**
     * The HTTP client timeout. Minting a URL runs no statement, so it gets a fixed limit rather than a statement
     * timeout plus a buffer.
     *
     * @var int
     */
    public const TIMEOUT_SECONDS = 30;

    protected Method $method = Method::POST;

    /**
     * @param string       $database     an unquoted identifier
     * @param string       $schema       an unquoted identifier
     * @param string       $name         the Streamlit object's unquoted identifier
     * @param string       $role         the role sent in X-Snowflake-Role, an unquoted identifier
     * @param ParentOrigin $parentOrigin the origin of the page that frames the app
     */
    public function __construct(
        private readonly string $database,
        private readonly string $schema,
        private readonly string $name,
        private readonly string $role,
        private readonly ParentOrigin $parentOrigin,
    ) {
    }

    /**
     * The JSON body: the origin the embed URL is bound to.
     *
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['parent_origin' => $this->parentOrigin->toString()];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => self::TIMEOUT_SECONDS];
    }

    /**
     * Snowflake requires the role and does not fall back to the user's default role.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['X-Snowflake-Role' => $this->role];
    }

    /**
     * The path below the connector's base URL.
     */
    public function resolveEndpoint(): string
    {
        return sprintf(
            '/databases/%s/schemas/%s/streamlits/%s:generate-embed-url',
            $this->database,
            $this->schema,
            $this->name
        );
    }
}
