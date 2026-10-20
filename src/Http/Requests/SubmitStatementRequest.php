<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Http\Requests;

use InterWorks\SnowflakeApi\Statement\Statement;
use SensitiveParameter;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * POST /api/v2/statements. Status handling is explicit in Snowflake::execute(); nothing here throws.
 */
final class SubmitStatementRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        #[SensitiveParameter] private readonly Statement $statement,
        private readonly int $httpTimeoutBufferSeconds = 5,
    ) {
    }

    /**
     * The JSON body: exactly Statement::toPayload().
     *
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->statement->toPayload();
    }

    /**
     * The HTTP client timeout depends on the statement, so the request sets it.
     *
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['timeout' => $this->statement->timeoutSeconds() + $this->httpTimeoutBufferSeconds];
    }

    /**
     * Asks Snowflake for JSON null instead of the string "null", with a fresh UUID v4 request id.
     *
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['nullable' => 'true', 'requestId' => self::uuidV4()];
    }

    /**
     * The path below the connector's base URL.
     */
    public function resolveEndpoint(): string
    {
        return '/statements';
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
