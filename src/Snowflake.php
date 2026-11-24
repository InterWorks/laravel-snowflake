<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi;

use InterWorks\SnowflakeApi\Auth\Authenticator;
use InterWorks\SnowflakeApi\Exceptions\AuthenticationException;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Exceptions\RateLimitedException;
use InterWorks\SnowflakeApi\Exceptions\SnowflakeException;
use InterWorks\SnowflakeApi\Exceptions\SqlException;
use InterWorks\SnowflakeApi\Exceptions\StatementStillRunningException;
use InterWorks\SnowflakeApi\Exceptions\TransportException;
use InterWorks\SnowflakeApi\Http\Requests\GenerateStreamlitEmbedUrlRequest;
use InterWorks\SnowflakeApi\Http\Requests\SubmitStatementRequest;
use InterWorks\SnowflakeApi\Http\SnowflakeConnector;
use InterWorks\SnowflakeApi\Result\Column;
use InterWorks\SnowflakeApi\Result\EmbedUrl;
use InterWorks\SnowflakeApi\Result\StatementResult;
use InterWorks\SnowflakeApi\Statement\Statement;
use InterWorks\SnowflakeApi\Support\AccountUrl;
use InterWorks\SnowflakeApi\Support\Identifier;
use InterWorks\SnowflakeApi\Support\ParentOrigin;
use JsonException;
use SensitiveParameter;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/**
 * The entry point: submits one statement, or mints one Streamlit embed URL, with a caller-supplied credential and
 * returns a typed result or throws a typed exception. It never retries, never caches and never logs.
 */
final class Snowflake
{
    public const VERSION = '0.1.0';

    /**
     * An absolute https URL with a host, no userinfo, and no whitespace or control characters anywhere, which is what
     * a 200 from generate-embed-url must carry before it can be handed to an iframe.
     */
    private const EMBED_URL_PATTERN = '#^https://[^\x00-\x20\x7f/?\#@\\\\:][^\x00-\x20\x7f/?\#@\\\\]*'
        . '(?:[/?\#][^\x00-\x20\x7f]*)?$#Di';

    private readonly SnowflakeConnector $connector;

    public function __construct(
        AccountUrl $accountUrl,
        Authenticator $authenticator,
        private readonly Options $options = new Options(),
    ) {
        $this->connector = new SnowflakeConnector($accountUrl, $authenticator, $this->options);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function buildResult(#[SensitiveParameter] array $body, int $elapsedMilliseconds): StatementResult
    {
        $meta = $body['resultSetMetaData'] ?? null;
        $data = $body['data'] ?? null;

        if (! is_array($meta) || ! is_array($data)) {
            throw self::unexpectedBody();
        }

        $rowType = $meta['rowType'] ?? null;
        $partitions = $meta['partitionInfo'] ?? null;
        $numRows = $meta['numRows'] ?? null;

        if (! is_array($rowType) || ! is_array($partitions) || ! is_int($numRows)) {
            throw self::unexpectedBody();
        }

        $columns = self::columnsFrom($rowType);

        return new StatementResult(
            statementHandle: self::string($body, 'statementHandle') ?? '',
            columns: $columns,
            rows: self::rowsFrom($data, count($columns)),
            numRows: $numRows,
            partitionCount: count($partitions),
            sqlState: self::string($body, 'sqlState') ?? '00000',
            message: self::string($body, 'message'),
            elapsedMilliseconds: $elapsedMilliseconds,
        );
    }

    /**
     * @param array<array-key, mixed> $rowType
     * @return list<Column>
     */
    private static function columnsFrom(array $rowType): array
    {
        $columns = [];

        foreach ($rowType as $definition) {
            if (! is_array($definition) || ! is_string($definition['name'] ?? null)
                || ! is_string($definition['type'] ?? null)) {
                throw self::unexpectedBody();
            }

            $precision = $definition['precision'] ?? null;
            $scale = $definition['scale'] ?? null;

            $columns[] = new Column(
                name: $definition['name'],
                type: $definition['type'],
                nullable: ($definition['nullable'] ?? true) !== false,
                precision: is_int($precision) ? $precision : null,
                scale: is_int($scale) ? $scale : null,
            );
        }

        return $columns;
    }

    /**
     * The Saloon connector. Exposed so tests can attach a MockClient.
     */
    public function connector(): SnowflakeConnector
    {
        return $this->connector;
    }

    /**
     * @return array<array-key, mixed>|null null when the body is not a JSON object or array
     */
    private static function decode(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Names the underlying exception class and message without keeping the exception itself.
     */
    private static function describe(Throwable $e): string
    {
        $source = $e->getPrevious() ?? $e;
        $class = substr(strrchr('\\' . $source::class, '\\') ?: '', 1);

        return $class . ': ' . $source->getMessage();
    }

    /**
     * Submits one statement and returns the first partition of its result.
     *
     * @throws AuthenticationException        401 or 403 from Snowflake.
     * @throws SqlException                   422, the statement failed to compile or execute.
     * @throws StatementStillRunningException 202, the statement outlived the timeout.
     * @throws RateLimitedException           429.
     * @throws TransportException             connection failure, HTTP timeout, 5xx, any other status, bad body.
     */
    public function execute(#[SensitiveParameter] Statement $statement): StatementResult
    {
        $request = new SubmitStatementRequest($statement, $this->options->httpTimeoutBufferSeconds);
        [$response, $status, $body, $elapsedMilliseconds] = $this->send($request);

        if ($status === 200) {
            if ($body === null) {
                throw self::unexpectedBody();
            }

            return self::buildResult($body, $elapsedMilliseconds);
        }

        throw self::failure($response, $status, $body, forStatement: true);
    }

    /**
     * @param array<array-key, mixed>|null $body the decoded body, or null when it was not JSON
     * @param bool                         $forStatement whether 202 and 422 carry their statement meanings
     */
    private static function failure(
        #[SensitiveParameter] Response $response,
        int $status,
        #[SensitiveParameter] ?array $body,
        bool $forStatement,
    ): SnowflakeException {
        if ($body === null) {
            return new TransportException(
                sprintf('Snowflake returned status %d with an unreadable body.', $status),
                $status
            );
        }

        $message = self::string($body, 'message');
        $code = self::string($body, 'code');
        $handle = self::string($body, 'statementHandle');

        return match (true) {
            $forStatement && $status === 202 => new StatementStillRunningException(
                $message ?? 'Snowflake is still running the statement.',
                $status,
                $code,
                $handle,
                self::string($body, 'statementStatusUrl'),
            ),
            $status === 401, $status === 403 => new AuthenticationException(
                $message ?? 'Snowflake rejected the credentials.',
                $status,
                $code,
            ),
            $forStatement && $status === 422 => new SqlException(
                $message ?? 'Snowflake could not run the statement.',
                $status,
                $code,
                $handle,
                self::string($body, 'sqlState'),
            ),
            $status === 429 => new RateLimitedException(
                $message ?? 'Snowflake rate limit reached.',
                $status,
                self::retryAfterSeconds($response),
                $code,
            ),
            default => new TransportException($message ?? 'Snowflake did not answer.', $status, $code),
        };
    }

    /**
     * Mints a single-use URL that loads a Streamlit in Snowflake app in an iframe on the given origin, as the identity
     * of this client's credential and with the given role.
     *
     * Snowflake requires the role (it never falls back to a default role), the EMBED and USAGE privileges on the app
     * for that role, and the origin in the account's STREAMLIT_EMBEDDING_CONTROLS. The URL is a bearer credential:
     * hand it to the browser that asked for it and nothing else. Nothing is resent, so a failure needs a new call.
     *
     * @param string              $database     the app's database, an unquoted identifier
     * @param string              $schema       the app's schema, an unquoted identifier
     * @param string              $name         the Streamlit object's name, an unquoted identifier
     * @param string              $role         sent as X-Snowflake-Role, an unquoted identifier
     * @param ParentOrigin|string $parentOrigin the origin of the page that frames the app
     *
     * @throws InvalidArgumentException when an identifier or the origin is refused, before anything is sent.
     * @throws AuthenticationException  401 or 403 from Snowflake.
     * @throws RateLimitedException     429.
     * @throws TransportException       connection failure, HTTP timeout, 5xx, any other status (including 400 and
     *                                  404), a 200 without an https embed_url, or an unreadable body.
     */
    public function generateStreamlitEmbedUrl(
        string $database,
        string $schema,
        string $name,
        string $role,
        ParentOrigin|string $parentOrigin,
    ): EmbedUrl {
        $request = new GenerateStreamlitEmbedUrlRequest(
            Identifier::assertUnquoted($database, 'database'),
            Identifier::assertUnquoted($schema, 'schema'),
            Identifier::assertUnquoted($name, 'Streamlit name'),
            Identifier::assertUnquoted($role, 'role'),
            $parentOrigin instanceof ParentOrigin ? $parentOrigin : ParentOrigin::fromString($parentOrigin),
        );
        [$response, $status, $body] = $this->send($request);

        if ($status === 200) {
            $url = $body['embed_url'] ?? null;

            if (! is_string($url) || preg_match(self::EMBED_URL_PATTERN, $url) !== 1) {
                throw new TransportException('Snowflake returned a 200 body without an https embed_url.', 200);
            }

            return new EmbedUrl($url);
        }

        throw self::failure($response, $status, $body, forStatement: false);
    }

    /**
     * Builds a client from a plain URL.
     *
     * Reads config('snowflake.*') for the options when running inside a Laravel container that has the config
     * repository bound, and uses the option defaults otherwise.
     *
     * @throws InvalidArgumentException when the URL is not an acceptable Snowflake account URL.
     */
    public static function make(string $accountUrl, Authenticator $authenticator): self
    {
        return new self(AccountUrl::fromString($accountUrl), $authenticator, Options::fromLaravelConfig());
    }

    private static function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (! is_string($header) || preg_match('/^\s*(\d{1,9})\s*$/D', $header, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<list<?string>>
     */
    private static function rowsFrom(array $data, int $columnCount): array
    {
        $rows = [];

        foreach ($data as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $columnCount) {
                throw self::unexpectedBody();
            }

            $cells = [];

            foreach ($row as $cell) {
                if ($cell !== null && ! is_string($cell)) {
                    throw self::unexpectedBody();
                }

                $cells[] = $cell;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Sends a request once and reads its answer: the response, its status, the decoded body (null when it is not
     * JSON) and the milliseconds the HTTP call took. Error statuses do not throw here; the caller maps them.
     *
     * @return array{0: Response, 1: int, 2: array<array-key, mixed>|null, 3: int}
     *
     * @throws TransportException when Snowflake cannot be reached or the response is larger than the limit.
     */
    private function send(#[SensitiveParameter] Request $request): array
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->connector->send($request);
        } catch (Throwable $e) {
            throw new TransportException('Could not reach Snowflake: ' . self::describe($e));
        }

        $elapsedMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $status = $response->status();
        $raw = $response->body();

        if (strlen($raw) > $this->options->maxResponseBytes) {
            throw new TransportException(
                sprintf('The Snowflake response exceeded the limit of %d bytes.', $this->options->maxResponseBytes),
                $status
            );
        }

        return [$response, $status, self::decode($raw), $elapsedMilliseconds];
    }

    /**
     * A string field of an error or result body. Integer codes are accepted and converted.
     *
     * @param array<array-key, mixed> $body
     */
    private static function string(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function unexpectedBody(): TransportException
    {
        return new TransportException('Snowflake returned an unexpected 200 body.', 200);
    }
}
