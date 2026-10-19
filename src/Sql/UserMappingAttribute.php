<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Sql;

/**
 * The Snowflake user attribute an External OAuth token claim is matched against.
 */
enum UserMappingAttribute: string
{
    case EmailAddress = 'EMAIL_ADDRESS';
    case LoginName = 'LOGIN_NAME';
}
