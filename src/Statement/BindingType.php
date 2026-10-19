<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Statement;

/**
 * The Snowflake SQL API bind variable types.
 */
enum BindingType: string
{
    case Binary = 'BINARY';
    case Boolean = 'BOOLEAN';
    case Date = 'DATE';
    case Fixed = 'FIXED';
    case Real = 'REAL';
    case Text = 'TEXT';
    case Time = 'TIME';
    case TimestampLtz = 'TIMESTAMP_LTZ';
    case TimestampNtz = 'TIMESTAMP_NTZ';
    case TimestampTz = 'TIMESTAMP_TZ';
}
