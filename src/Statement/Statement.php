<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Statement;

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Support\Identifier;

/**
 * An immutable description of one SQL statement. Every builder method returns a modified clone.
 *
 * The package executes exactly one statement per call. The builder never inspects or rewrites the SQL text.
 */
final class Statement
{
    public const DEFAULT_TIMEOUT_SECONDS = 45;

    private const MAX_QUERY_TAG_LENGTH = 2000;

    /** @var array<int, Binding> keyed by position */
    private array $bindings = [];

    private ?string $database = null;

    /** @var array<string, string> */
    private array $parameters = [];

    private ?string $queryTag = null;

    private ?string $role = null;

    private ?string $schema = null;

    private int $timeout = self::DEFAULT_TIMEOUT_SECONDS;

    private ?string $warehouse = null;

    private function __construct(private readonly string $sql)
    {
    }

    /**
     * Creates a statement from SQL text.
     *
     * @throws InvalidArgumentException when the SQL is empty after trimming or is not valid UTF-8.
     */
    public static function make(string $sql): self
    {
        if (trim($sql) === '') {
            throw new InvalidArgumentException('The SQL text must not be empty.');
        }

        self::assertUtf8($sql, 'SQL text');

        return new self($sql);
    }

    /**
     * Binds a value to the positional placeholder at the given 1-based position.
     *
     * @throws InvalidArgumentException when the position is below 1 or already bound, or the value is not UTF-8.
     */
    public function bind(int $position, BindingType $type, ?string $value): self
    {
        if ($position < 1) {
            throw new InvalidArgumentException('A binding position must be 1 or greater.');
        }

        if (isset($this->bindings[$position])) {
            throw new InvalidArgumentException(sprintf('Position %d is already bound.', $position));
        }

        if ($value !== null) {
            self::assertUtf8($value, 'binding value');
        }

        $clone = clone $this;
        $clone->bindings[$position] = new Binding($position, $type, $value);

        return $clone;
    }

    /**
     * The bindings ordered by position.
     *
     * @return list<Binding>
     */
    public function bindings(): array
    {
        $bindings = $this->bindings;
        ksort($bindings);

        return array_values($bindings);
    }

    /**
     * Sets the database the statement runs in.
     *
     * @throws InvalidArgumentException when the value is not an unquoted identifier.
     */
    public function database(string $identifier): self
    {
        $clone = clone $this;
        $clone->database = Identifier::assertUnquoted($identifier, 'database');

        return $clone;
    }

    /**
     * Sets a session parameter for this statement.
     *
     * @throws InvalidArgumentException for MULTI_STATEMENT_COUNT, QUERY_TAG, an invalid name or a non-UTF-8 value.
     */
    public function parameter(string $name, string $value): self
    {
        Identifier::assertUnquoted($name, 'parameter name');

        $upper = strtoupper($name);

        if ($upper === 'MULTI_STATEMENT_COUNT') {
            throw new InvalidArgumentException(
                'MULTI_STATEMENT_COUNT is not allowed. The package executes exactly one statement per call.'
            );
        }

        if ($upper === 'QUERY_TAG') {
            throw new InvalidArgumentException('QUERY_TAG is not allowed here. Use queryTag() instead.');
        }

        self::assertUtf8($value, 'parameter value');

        $clone = clone $this;
        $clone->parameters[$name] = $value;

        return $clone;
    }

    /**
     * Sets the QUERY_TAG session parameter.
     *
     * @throws InvalidArgumentException when the tag exceeds 2000 characters, is not UTF-8 or has control characters.
     */
    public function queryTag(string $tag): self
    {
        self::assertUtf8($tag, 'query tag');

        if (mb_strlen($tag, 'UTF-8') > self::MAX_QUERY_TAG_LENGTH) {
            throw new InvalidArgumentException('The query tag must not be longer than 2000 characters.');
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $tag) === 1) {
            throw new InvalidArgumentException('The query tag must not contain control characters.');
        }

        $clone = clone $this;
        $clone->queryTag = $tag;

        return $clone;
    }

    /**
     * Sets the role the statement runs under.
     *
     * @throws InvalidArgumentException when the value is not an unquoted identifier.
     */
    public function role(string $identifier): self
    {
        $clone = clone $this;
        $clone->role = Identifier::assertUnquoted($identifier, 'role');

        return $clone;
    }

    /**
     * Sets the schema the statement runs in.
     *
     * @throws InvalidArgumentException when the value is not an unquoted identifier.
     */
    public function schema(string $identifier): self
    {
        $clone = clone $this;
        $clone->schema = Identifier::assertUnquoted($identifier, 'schema');

        return $clone;
    }

    /**
     * The SQL text exactly as supplied.
     */
    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * Sets the statement execution limit in seconds.
     *
     * @throws InvalidArgumentException when the timeout is below 1.
     */
    public function timeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The timeout must be 1 second or more.');
        }

        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    /**
     * The statement execution limit in seconds. Defaults to 45.
     */
    public function timeoutSeconds(): int
    {
        return $this->timeout;
    }

    /**
     * The exact JSON body for POST /api/v2/statements. Keys appear only when set, in a fixed order.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'statement' => $this->sql,
            'timeout' => $this->timeout,
        ];

        if ($this->warehouse !== null) {
            $payload['warehouse'] = $this->warehouse;
        }

        if ($this->database !== null) {
            $payload['database'] = $this->database;
        }

        if ($this->schema !== null) {
            $payload['schema'] = $this->schema;
        }

        if ($this->role !== null) {
            $payload['role'] = $this->role;
        }

        if ($this->bindings !== []) {
            $bindings = [];

            foreach ($this->bindings() as $binding) {
                $bindings[(string) $binding->position] = ['type' => $binding->type->value, 'value' => $binding->value];
            }

            $payload['bindings'] = $bindings;
        }

        $parameters = $this->parameters;

        if ($this->queryTag !== null) {
            $parameters = ['QUERY_TAG' => $this->queryTag] + $parameters;
        }

        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        return $payload;
    }

    /**
     * Sets the warehouse the statement runs on.
     *
     * @throws InvalidArgumentException when the value is not an unquoted identifier.
     */
    public function warehouse(string $identifier): self
    {
        $clone = clone $this;
        $clone->warehouse = Identifier::assertUnquoted($identifier, 'warehouse');

        return $clone;
    }

    private static function assertUtf8(string $value, string $field): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf('The %s must be valid UTF-8.', $field));
        }
    }
}
