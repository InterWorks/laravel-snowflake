# interworks/laravel-snowflake

[![CI](https://github.com/InterWorks/laravel-snowflake/actions/workflows/ci.yml/badge.svg)](https://github.com/InterWorks/laravel-snowflake/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

A small, strict client for the [Snowflake SQL API v2](https://docs.snowflake.com/en/developer-guide/sql-api/index),
built for Laravel.

You give it an account URL, a credential you already hold, and one SQL statement. It gives you back a typed result or
throws a typed exception. That is all it does, on purpose.

```php
$result = Snowflake::make($accountUrl, new OAuthAccessToken($jwt))->execute(
    Statement::make('SELECT region, SUM(revenue) FROM sales WHERE year = ? GROUP BY region')
        ->bind(1, BindingType::Fixed, '2026')
        ->warehouse('ANALYTICS_WH')
);
```

**Contents:** [At a glance](#at-a-glance) · [Install](#install) · [Quick start](#quick-start) ·
[Authenticators](#authenticators) · [Statement builder](#statement-builder) · [Results](#results) ·
[Errors](#errors) · [Configuration](#configuration) · [External OAuth setup helper](#external-oauth-setup-helper) ·
[Testing](#testing) · [Security](#security) · [FAQ](#faq) · [Roadmap](#roadmap) · [License](#license)

## At a glance

| It does | It does not |
| --- | --- |
| Submit one statement and return the first result partition | Run several statements in one call |
| Authenticate with an OAuth access token or a Programmatic Access Token | Sign JWTs or hold private keys |
| Map every Snowflake HTTP status to a typed exception | Retry, back off or resend anything |
| Keep tokens out of messages, dumps and stack traces | Log, cache or inspect your SQL |
| Return values exactly as Snowflake sent them (strings or null) | Cast, hydrate or map rows to models |
| Generate the SQL text for an External OAuth integration | Decide who may run what (Snowflake does, through the token's role) |

It is a transport layer. If you want an ORM, a query builder or a connection pool, this is not that package. If you want
to send a governed query on behalf of a user and know exactly what can go wrong, it is.

## Install

```bash
composer require interworks/laravel-snowflake
```

| Requirement | Supported |
| --- | --- |
| PHP | 8.3, 8.4 |
| Laravel | 12, 13 |

Both are tested in CI. Laravel 11 is not supported: Composer refuses to install any 11.x release because of published
security advisories, so a package claiming support would only be testable against known-vulnerable code.

The service provider is discovered automatically. It registers one config file and binds nothing. To publish the config:

```bash
php artisan vendor:publish --tag=snowflake-api-config
```

## Quick start

```php
use InterWorks\SnowflakeApi\Auth\OAuthAccessToken;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\Statement\BindingType;
use InterWorks\SnowflakeApi\Statement\Statement;

$snowflake = Snowflake::make('https://myorg-myaccount.snowflakecomputing.com', new OAuthAccessToken($jwt));
$result    = $snowflake->execute(
    Statement::make('SELECT region, SUM(revenue) AS revenue FROM governed.sales WHERE year = ? GROUP BY region')
        ->bind(1, BindingType::Fixed, '2026')
        ->warehouse('ANALYTICS_WH')
        ->timeout(30)
);
foreach ($result->rows as $row) { /* ... */ }
```

`Snowflake` is cheap and stateless, so build one per request with that user's token. A typical Laravel service:

```php
final class SalesReport
{
    public function forYear(string $userJwt, int $year): StatementResult
    {
        $snowflake = Snowflake::make(config('services.snowflake.account_url'), new OAuthAccessToken($userJwt));

        return $snowflake->execute(
            Statement::make('SELECT region, SUM(revenue) FROM governed.sales WHERE year = ? GROUP BY region')
                ->bind(1, BindingType::Fixed, (string) $year)
                ->warehouse('ANALYTICS_WH')
                ->queryTag('sales-report')
        );
    }
}
```

Keep the account URL in your own configuration. Never take it from user input (see [Security](#security)).

## Authenticators

| Class | Header `X-Snowflake-Authorization-Token-Type` | Use it for |
| --- | --- | --- |
| `Auth\OAuthAccessToken` | `OAUTH` | A short-lived External OAuth JWT or any OAuth access token you hold |
| `Auth\ProgrammaticAccessToken` | `PROGRAMMATIC_ACCESS_TOKEN` | A Snowflake PAT, handy for trying the package against a real account |

The token is stored inside a closure, masked with a random key, so `dd()`, `var_dump`, `print_r`, `var_export`,
`json_encode`, `serialize()` and stack traces do not show it (this guards against accidental disclosure, not against code
running inside your process), and the package asks for it once per request without caching it. Tokens must be non-empty,
visible ASCII, which also rules out header injection.

To authenticate another way, implement `Auth\Authenticator`. For example, key-pair authentication works today if you
sign the JWT yourself and return `'KEYPAIR_JWT'` from `tokenType()`. A built-in authenticator is on the roadmap.

## Statement builder

`Statement` is immutable: every method returns a modified clone, so a base statement can be shared safely.

```php
$base = Statement::make('SELECT * FROM orders WHERE id = ? AND region = ?')
    ->warehouse('ANALYTICS_WH')
    ->database('GOVERNED')
    ->schema('PUBLIC')
    ->role('ANALYST_ROLE')
    ->timeout(30)                             // statement limit in seconds, default 45
    ->queryTag('app:user:12')             // QUERY_TAG, up to 2000 characters
    ->parameter('TIMEZONE', 'UTC');           // any other session parameter

$one = $base->bind(1, BindingType::Fixed, '42')->bind(2, BindingType::Text, null);   // null is sent as JSON null
```

The builder refuses, with `Exceptions\InvalidArgumentException`:

- `MULTI_STATEMENT_COUNT` in any letter case. The package runs exactly one statement per call, and this is not
  configurable.
- `QUERY_TAG` through `parameter()`. Use `queryTag()`.
- Identifiers other than unquoted ones matching `^[A-Za-z_][A-Za-z0-9_$]{0,254}$`.
- Binding positions below 1 or used twice, and timeouts below 1.
- SQL text, binding values, parameter values and tags that are not valid UTF-8.

The builder never reads your SQL. Use bindings for values; if you concatenate untrusted input into the SQL string,
nothing in the package can protect you.

The HTTP timeout is the statement timeout plus a buffer (5 seconds by default), so Snowflake gets to answer first.

## Results

`execute()` returns a `Result\StatementResult`:

| Property | Meaning |
| --- | --- |
| `columns` | `list<Column>`: `name`, `type` (verbatim, such as `fixed` or `text`), `nullable`, `precision`, `scale` |
| `rows` | `list<list<?string>>`, exactly as Snowflake sent them |
| `numRows` | Row count across **all** partitions |
| `partitionCount` | Number of partitions. `hasMorePartitions()` is true when it is above 1 |
| `statementHandle`, `sqlState`, `message` | From the response |
| `elapsedMilliseconds` | Measured around the HTTP call |

Two behaviours to know about:

**Every value is a string or null.** Snowflake's `jsonv2` format sends everything as text, and casting policy is yours.
`Column::type` tells you what you are holding:

```php
$typed = array_map(
    static fn (array $row): array => array_map(
        static fn (?string $cell, Column $column): int|float|bool|string|null => match (true) {
            $cell === null => null,
            $column->type === 'fixed' && ($column->scale ?? 0) === 0 => (int) $cell,
            $column->type === 'fixed', $column->type === 'real' => (float) $cell,
            $column->type === 'boolean' => $cell === 'true',
            default => $cell,
        },
        $row,
        $result->columns,
    ),
    $result->rows,
);
```

Floats lose precision. Keep money as strings and use `bcmath` or a decimal library.

**Only the first partition is returned.** A large result is split into partitions and only the first comes back. If
`hasMorePartitions()` is true, `rows` is a partial answer, and ignoring that is how reports end up silently wrong.
Narrow the query, add a `LIMIT`, or wait for partition fetching in 0.2.0.

## Errors

Every failure extends `Exceptions\SnowflakeException`, which carries `httpStatus`, `snowflakeCode` and
`statementHandle`.

| Snowflake answer | Result | Extra fields |
| --- | --- | --- |
| 200, well-formed | `StatementResult` | |
| 200, malformed body | `TransportException` | |
| 202, still running | `StatementStillRunningException` | `statementStatusUrl` |
| 401, 403 | `AuthenticationException` | |
| 422 | `SqlException` | `sqlState` |
| 429 | `RateLimitedException` | `retryAfterSeconds` (from `Retry-After`, else null) |
| 408, 5xx, any other status, redirects, connection or TLS failure, timeout, oversized or unreadable body | `TransportException` | |

```php
try {
    $result = $snowflake->execute($statement);
} catch (RateLimitedException $e) {
    // $e->retryAfterSeconds is a hint. Whether to retry is your decision.
} catch (SqlException $e) {
    // Bad SQL or a missing object. $e->sqlState and $e->snowflakeCode say which.
} catch (AuthenticationException $e) {
    // The token was rejected or the role lacks privilege.
} catch (SnowflakeException $e) {
    // Everything else, including a statement that is still running (202).
}
```

Every exception obeys three rules, and every test asserts them:

1. The message is Snowflake's own `message`, or a fixed sentence. It never contains a token, a header, the request body
   or the SQL text.
2. No previous exception is chained, because Guzzle and Saloon exceptions carry the `Authorization` header. Only the
   underlying class name and message are copied, after masking anything shaped like `Bearer <token>`.
3. Nothing is resent. After a 5xx or a timeout you cannot know whether the statement ran, and resending an `INSERT`
   could duplicate data.

A 202 means the statement outlived the synchronous wait and may still be running. Version 0.1.0 reports it; polling and
cancelling arrive in 0.2.0.

## Configuration

Everything has a default, and the config file is optional. Publish it, or set environment variables:

| Config key | Environment variable | Default | Meaning |
| --- | --- | --- | --- |
| `snowflake-api.http_timeout_buffer` | `SNOWFLAKE_HTTP_TIMEOUT_BUFFER` | `5` | Seconds added to the statement timeout |
| `snowflake-api.connect_timeout` | `SNOWFLAKE_CONNECT_TIMEOUT` | `10` | Seconds allowed to open the connection |
| `snowflake-api.user_agent_suffix` | `SNOWFLAKE_USER_AGENT_SUFFIX` | none | Appended to the User-Agent, so Snowflake's query history can tell consumers apart |
| `snowflake-api.max_response_bytes` | `SNOWFLAKE_MAX_RESPONSE_BYTES` | `67108864` | The transfer is aborted past this many bytes on the wire (64 MiB) |

`Snowflake::make()` reads these inside a Laravel app and falls back to defaults elsewhere. To skip Laravel entirely,
construct `new Snowflake($accountUrl, $authenticator, new Options(...))` yourself.

## External OAuth setup helper

`Sql\ExternalOAuthIntegration` writes the SQL an administrator runs to trust your token issuer. **It only produces text;
it executes nothing.**

```php
$sql = ExternalOAuthIntegration::createSql(
    integrationName: 'APP_OAUTH',
    issuer: 'https://app.example.com',                      // must equal the token's iss claim byte for byte
    jwksUrl: 'https://app.example.com/.well-known/jwks.json',
    audiences: ['https://myorg-myaccount.snowflakecomputing.com'],
);
```

When Snowflake cannot reach your JWKS URL (an issuer behind a firewall), paste the public key instead. The key is
accepted as a PEM block or its bare base64 body, and a key rotation needs the statement run again:

```php
$sql = ExternalOAuthIntegration::createSqlWithPublicKey(
    integrationName: 'APP_OAUTH',
    issuer: 'https://app.example.com',
    rsaPublicKey: $publicKeyPem,
    audiences: ['https://myorg-myaccount.snowflakecomputing.com'],
    orReplace: true,                                       // CREATE OR REPLACE, so re-running is safe
);
```

Defaults map the token's `email` claim to the Snowflake user's `EMAIL_ADDRESS` and enable any-role mode. Override
`userMappingClaim`, `mappingAttribute`, `anyRoleMode` and `scopeMappingAttribute` for other setups, and pass
`orReplace: true` to either builder when the statement must be re-runnable. Pass `allowedRoles: ['APP_READER']` to
emit `EXTERNAL_OAUTH_ALLOWED_ROLES_LIST`, so Snowflake refuses a token for any other role even when the issuer's
signing key leaks. When Snowflake rejects a token, an administrator can ask it why:

```php
ExternalOAuthIntegration::verifyTokenSql($jwt);   // SELECT SYSTEM$VERIFY_EXTERNAL_OAUTH_TOKEN('...');
```

String literals have quotes and backslashes doubled, and arguments containing control characters, newlines or NUL bytes
are refused.

## Testing

Your own tests never need the network. Attach Saloon's `MockClient` to the connector:

```php
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

$snowflake = new Snowflake(AccountUrl::fromString('https://myorg-myaccount.snowflakecomputing.com'), $authenticator);
$mock      = new MockClient([MockResponse::make($jsonBody, 200)]);
$snowflake->connector()->withMockClient($mock);

$result = $snowflake->execute(Statement::make('SELECT 1'));

$mock->assertSent(SubmitStatementRequest::class);
$mock->getLastPendingRequest()->body()->all();   // exactly what would have been sent
```

`tests/Fixtures/` holds realistic Snowflake bodies for 200, 202, 401, 403, 422, 429 and 500 that you can copy.

To work on the package itself, start with the [developer walkthrough](docs/WALKTHROUGH.md):

```bash
composer install
composer check        # Pint, PHPStan level 8, Pest (including architecture tests)
composer fix          # apply code style
```

A live test exists for maintainers. It talks to a real account and runs only when `SNOWFLAKE_LIVE=1`,
`SNOWFLAKE_ACCOUNT_URL` and `SNOWFLAKE_PAT` are exported in your shell: `composer test:live`. Never write those values
into a file.

## Security

**Trust model.** The package trusts you to supply the account URL and the credential, and trusts Snowflake's answers.
It does not trust SQL, identifiers or option values, and validates them.

**Treat the account URL as configuration, never user input.** The URL must be `https` under `snowflakecomputing.com` or
`snowflakecomputing.cn`, but that includes every Snowflake customer's account. If an end user could choose the URL, they
could point it at an account they control and receive your bearer token. A README cannot stop that. Only your
application can, by reading the URL from its own configuration. When a lookup by tenant is unavoidable, resolve it from
a list you maintain.

**Token handling.** The token never appears in an exception message, a dump or a stack trace the package produces. Your
own code can still leak it, for example by logging `$jwt`. Mint short-lived tokens, keep them out of logs and do not
store them.

**Transport.** TLS certificates are verified. Redirects are disabled, so a statement is never resent and a `Location`
header is never followed. The connector is pinned to the Guzzle sender, so a global `saloon.default_sender` override
cannot route requests through a client that records headers. Responses larger than `max_response_bytes` are aborted.
The limit counts bytes on the wire, before decompression.

**Proxies.** The HTTP client honours the standard proxy environment variables (`HTTPS_PROXY`, `ALL_PROXY`, `NO_PROXY`).
If one is set, requests and their bearer token pass through that proxy. Make sure it is yours, or exclude Snowflake
hosts with `NO_PROXY`.

**SQL and authorization.** The package never inspects SQL and never decides who may run what. Authorization is
Snowflake's: the token's identity and role decide what a statement can touch. Use bindings for values.

**Reporting a vulnerability.** Please do not open a public issue. Use the repository's **Security → Report a
vulnerability** page on GitHub.

## FAQ

**Why not Laravel's `Http` client or Guzzle directly?** Saloon gives typed requests and a first-class `MockClient`, and
the package pins the sender so behaviour does not depend on app-level HTTP configuration.

**Why are values strings?** Casting is policy: floats or decimals, timezones, booleans. The package returns what
Snowflake sent and leaves that choice to the consumer. See [Results](#results) for a ready-made mapping.

**Why only the first partition?** Fetching later partitions is a separate API call with its own failure modes. The
package reports `partitionCount` so you can detect a partial result, and partition fetching is planned for 0.2.0.

**Why no retries?** A statement may write data. After a timeout or a 5xx you cannot tell whether it ran, so resending
could duplicate effects. The decision belongs to the caller, who knows whether the statement is idempotent.

**Why is there no facade or container binding?** A shared instance would imply one shared credential. Build one per
credential. The package keeps no static mutable state, so it is safe under Octane and in queue workers.

**Can I run several statements at once?** No. One statement per call is enforced, because it keeps error handling and
authorization unambiguous.

**How do I tell my queries apart in Snowflake's history?** Set `queryTag()` per statement and `user_agent_suffix` per
application.

**Does it support key-pair authentication?** Not as a built-in authenticator yet. You can implement `Authenticator`
yourself with token type `KEYPAIR_JWT`.

## Roadmap

- **0.2.0:** poll a statement handle, fetch partition N, cancel a statement, `executeAsync()`.
- **0.3.0:** key-pair JWT authenticator.
- **0.4.0:** Streamlit in Snowflake `generate-embed-url` endpoint.
- **Never:** SQL inspection or rewriting, result caching, authorization decisions, value casting policies.

See [CHANGELOG.md](CHANGELOG.md) for released changes.

## License

MIT. See [LICENSE.md](LICENSE.md).
