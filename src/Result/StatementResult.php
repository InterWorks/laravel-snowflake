<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Result;

/**
 * The first partition of a statement result. Values are never cast: every cell is a string or null.
 */
final readonly class StatementResult
{
    /**
     * @param list<Column>        $columns
     * @param list<list<?string>> $rows            first partition only, values exactly as Snowflake sent them
     * @param int                 $numRows         resultSetMetaData.numRows, across all partitions
     * @param int                 $partitionCount  count of resultSetMetaData.partitionInfo
     */
    public function __construct(
        public string $statementHandle,
        public array $columns,
        public array $rows,
        public int $numRows,
        public int $partitionCount,
        public string $sqlState,
        public ?string $message,
        public int $elapsedMilliseconds,
    ) {
    }

    /**
     * True when the result has partitions beyond the first, which this package does not fetch.
     */
    public function hasMorePartitions(): bool
    {
        return $this->partitionCount > 1;
    }

    /**
     * A plain array form for serialisation.
     *
     * @return array{
     *     columns: list<array{name: string, type: string}>,
     *     rows: list<list<?string>>,
     *     numRows: int,
     *     partitionCount: int
     * }
     */
    public function toArray(): array
    {
        return [
            'columns' => array_map(
                static fn (Column $column): array => ['name' => $column->name, 'type' => $column->type],
                $this->columns
            ),
            'rows' => $this->rows,
            'numRows' => $this->numRows,
            'partitionCount' => $this->partitionCount,
        ];
    }
}
