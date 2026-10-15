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
use InterWorks\SnowflakeApi\Http\Requests\SubmitStatementRequest;
use InterWorks\SnowflakeApi\Http\SnowflakeConnector;
use InterWorks\SnowflakeApi\Result\Column;
use InterWorks\SnowflakeApi\Result\StatementResult;
use InterWorks\SnowflakeApi\Statement\Statement;
use InterWorks\SnowflakeApi\Support\AccountUrl;
use JsonException;
use Saloon\Http\Response;
use Throwable;

/**
 * The entry point: submits one statement with a caller-supplied credential and returns a typed result or throws a
 * typed exception. It never retries, never caches and never logs.
 */
final class Snowflake
{
    public const VERSION = '0.1.0';

    private readonly SnowflakeConnector $connector;

    public function __construct(
        AccountUrl $accountUrl,
        Authenticator $authenticator,
        private readonly Options $options = new Options(),
    ) {
        $this->connector = new SnowflakeConnector($accountUrl, $authenticator, $this->options);
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

    /**
     * The Saloon connector. Exposed so tests can attach a MockClient.
     */
    public function connector(): SnowflakeConnector
    {
        return $this->connector;
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
    public function execute(Statement $statement): StatementResult
    {
        $request = new SubmitStatementRequest($statement, $this->options->httpTimeoutBufferSeconds);
        $startedAt = hrtime(true);

        try {
            $response = $this->connector->send($request);
        } catch (Throwable $e) {
            throw new TransportException('Could not reach Snowflake: ' . self::describe($e));
        }

        $elapsedMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $status = $response->status();
        $body = self::decode($response->body());

        if ($status === 200) {
            if ($body === null) {
                throw self::unexpectedBody();
            }

            return self::buildResult($body, $elapsedMilliseconds);
        }

        if ($body === null) {
            throw new TransportException(
                sprintf('Snowflake returned status %d with an unreadable body.', $status),
                $status
            );
        }

        throw self::failure($response, $status, $body);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function buildResult(array $body, int $elapsedMilliseconds): StatementResult
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
     * @param array<array-key, mixed> $body
     */
    private static function failure(Response $response, int $status, array $body): SnowflakeException
    {
        $message = self::string($body, 'message');
        $code = self::string($body, 'code');
        $handle = self::string($body, 'statementHandle');

        return match (true) {
            $status === 202 => new StatementStillRunningException(
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
            $status === 422 => new SqlException(
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
