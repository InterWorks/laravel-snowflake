<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Statement;

/**
 * One positional bind variable. The value travels as a string, or as JSON null when it is null.
 */
final readonly class Binding
{
    public function __construct(public int $position, public BindingType $type, public ?string $value)
    {
    }
}
