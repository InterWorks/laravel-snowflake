<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Result;

/**
 * One result column, taken from resultSetMetaData.rowType.
 */
final readonly class Column
{
    /**
     * @param string   $type Snowflake rowType.type, for example "fixed" or "text", verbatim
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?int $precision,
        public ?int $scale,
    ) {
    }
}
