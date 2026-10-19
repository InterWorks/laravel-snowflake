<?php

declare(strict_types=1);

return [
    // Seconds added to the statement timeout to form the HTTP client timeout.
    'http_timeout_buffer' => (int) env('SNOWFLAKE_HTTP_TIMEOUT_BUFFER', 5),
    // Seconds allowed to open the TCP/TLS connection.
    'connect_timeout' => (int) env('SNOWFLAKE_CONNECT_TIMEOUT', 10),
    // Appended to the User-Agent so Snowflake query history can tell consumers apart.
    'user_agent_suffix' => env('SNOWFLAKE_USER_AGENT_SUFFIX'),
    // The transfer is aborted once more than this many bytes arrive (default 64 MiB). The count is bytes on the wire,
    // before decompression.
    'max_response_bytes' => (int) env('SNOWFLAKE_MAX_RESPONSE_BYTES', 67_108_864),
];
