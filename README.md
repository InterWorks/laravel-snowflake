# interworks/laravel-snowflake

A Snowflake SQL API v2 client for Laravel. It submits one SQL statement with a credential you supply and returns a typed
result or throws a typed exception. It is transport only: it never decides who may run what, never inspects SQL, never
caches, never retries and never logs.

## Install

```bash
composer require interworks/laravel-snowflake
```

Requires PHP 8.3 or newer and Laravel 11, 12 or 13. The service provider is discovered automatically. To publish the
config file:

```bash
php artisan vendor:publish --tag=snowflake-config
```

You can ignore the provider and config entirely and construct the client yourself.

## Quick start

```php
$snowflake = Snowflake::make('https://myorg-myaccount.snowflakecomputing.com', new OAuthAccessToken($jwt));
$result    = $snowflake->execute(
    Statement::make('SELECT region, SUM(revenue) AS revenue FROM governed.sales WHERE year = ? GROUP BY region')
        ->bind(1, BindingType::Fixed, '2026')
        ->warehouse('ANALYTICS_WH')
        ->timeout(30)
);
foreach ($result->rows as $row) { /* ... */ }
```

Classes live under `InterWorks\SnowflakeApi`. The account URL must be `https`, have no path, query, fragment, port or
userinfo, and its host must end in `snowflakecomputing.com` or `snowflakecomputing.cn`.

## Authenticators

| Class | Header `X-Snowflake-Authorization-Token-Type` | Use |
| --- | --- | --- |
| `Auth\OAuthAccessToken` | `OAUTH` | A caller-supplied OAuth access token, such as a short-lived External OAuth JWT. |
| `Auth\ProgrammaticAccessToken` | `PROGRAMMATIC_ACCESS_TOKEN` | A Snowflake PAT, handy for trying the package against a real account. |

The token is held inside a closure, so `var_dump`, `print_r`, `var_export`, `json_encode`, `serialize` and stack traces
do not show it. The package asks for the token once per request and never stores or logs it. Implement
`Auth\Authenticator` to supply your own.

## Statement builder

`Statement` is immutable: every method returns a modified clone.

```php
Statement::make('SELECT * FROM orders WHERE id = ? AND region = ?')
    ->bind(1, BindingType::Fixed, '42')
    ->bind(2, BindingType::Text, null)       // null is sent as JSON null
    ->warehouse('ANALYTICS_WH')
    ->database('GOVERNED')
    ->schema('PUBLIC')
    ->role('CURATOR_VIEWER')
    ->timeout(30)                            // seconds, default 45
    ->queryTag('curator:user:12')            // QUERY_TAG, up to 2000 characters
    ->parameter('TIMEZONE', 'UTC');          // any other session parameter
```

Rules, each raising `Exceptions\InvalidArgumentException`:

- One statement per call. `parameter('MULTI_STATEMENT_COUNT', ...)` is refused in any letter case, and `QUERY_TAG` must
  go through `queryTag()`.
- Identifiers are unquoted only: `^[A-Za-z_][A-Za-z0-9_$]{0,254}$`.
- Binding positions start at 1 and must be unique. Values are sent as strings.
- SQL text, binding values, parameter values and the query tag must be valid UTF-8.

The HTTP timeout is the statement timeout plus a buffer (5 seconds by default).

## Results

`execute()` returns a `Result\StatementResult` with the first partition only:

| Property | Meaning |
| --- | --- |
| `columns` | `list<Column>` with `name`, `type` (verbatim, such as `fixed` or `text`), `nullable`, `precision`, `scale` |
| `rows` | `list<list<?string>>`, exactly as Snowflake sent them. The package never casts values. |
| `numRows` | Row count across all partitions |
| `partitionCount` | Number of partitions. `hasMorePartitions()` is true when it is above 1. |
| `statementHandle`, `sqlState`, `message` | From the response |
| `elapsedMilliseconds` | Measured around the HTTP call |

Fetching later partitions is not part of 0.1.0. Check `hasMorePartitions()` and decide what to do.

## Errors

Every exception extends `Exceptions\SnowflakeException`, which carries `httpStatus`, `snowflakeCode` and
`statementHandle`.

| Snowflake answer | Exception |
| --- | --- |
| 200 with the expected body | `StatementResult` |
| 200 with a malformed body | `TransportException` |
| 202 (still running) | `StatementStillRunningException` with `statementStatusUrl` |
| 401, 403 | `AuthenticationException` |
| 422 | `SqlException` with `sqlState` |
| 429 | `RateLimitedException` with `retryAfterSeconds` |
| 408, 5xx, any other status, connection failure, timeout, unreadable body | `TransportException` |

Messages are Snowflake's own `message` field, or a fixed sentence. They never contain a token, a header, the request body
or the SQL text. No previous exception is chained, because Guzzle and Saloon exceptions carry the `Authorization`
header. The package never resends a statement.

## External OAuth setup helper

`Sql\ExternalOAuthIntegration` produces SQL text for an administrator to run. It executes nothing.

```php
$sql = ExternalOAuthIntegration::createSql(
    integrationName: 'CURATOR_OAUTH',
    issuer: 'https://curator.example.com',
    jwksUrl: 'https://curator.example.com/.well-known/jwks.json',
    audiences: ['https://myorg-myaccount.snowflakecomputing.com'],
);

$check = ExternalOAuthIntegration::verifyTokenSql($jwt);   // SELECT SYSTEM$VERIFY_EXTERNAL_OAUTH_TOKEN('...');
```

String literals have single quotes and backslashes doubled. Arguments containing control characters are refused.
`$issuer` must equal the token's `iss` claim byte for byte.

## Testing

Attach Saloon's `MockClient` to the connector. No network is needed.

```php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

$snowflake = new Snowflake(AccountUrl::fromString('https://myorg-myaccount.snowflakecomputing.com'), $authenticator);
$mock = new MockClient([MockResponse::make($jsonBody, 200)]);
$snowflake->connector()->withMockClient($mock);

$result = $snowflake->execute(Statement::make('SELECT 1'));

$mock->assertSent(SubmitStatementRequest::class);
```

Run the package's own checks with `composer check` (Pint, PHPStan level 8, Pest). The opt-in live test needs
`SNOWFLAKE_LIVE=1`, `SNOWFLAKE_ACCOUNT_URL` and `SNOWFLAKE_PAT` exported in your shell, then `composer test:live`.

## Roadmap

- 0.2.0: poll a statement handle, fetch partition N, cancel a statement, `executeAsync()`.
- 0.3.0: key-pair JWT authenticator.
- 0.4.0: Streamlit in Snowflake `generate-embed-url` endpoint.
- Never: SQL inspection or rewriting, result caching, authorization decisions, value casting policies.

## License

MIT. See [LICENSE.md](LICENSE.md).
