# Developer walkthrough

For anyone about to read, change or release this package. About twenty minutes, and you will know where everything is
and what not to break. The public contract is in the [README](../README.md); this document is about the code behind it.

## 1. What you are working on

A Laravel-friendly client for the Snowflake SQL API v2. Your code hands it an account URL, a credential and one SQL
statement; it returns the first result partition or throws a typed exception.

The package is deliberately small and deliberately strict. It is **transport only**: no retries, no caching, no logging,
no SQL inspection, no authorization decisions, no value casting. Most review comments on a change to this package come
down to "does this make it do something it promised not to do?" Section 6 lists the promises and where each is enforced.

## 2. Get it running

Requirements: PHP 8.3 or newer and Composer.

```bash
git clone https://github.com/InterWorks/laravel-snowflake.git
cd laravel-snowflake
composer install
composer check
```

`composer check` runs the main CI checks: parallel-lint (syntax), Pint and PHPCS (style), PHPStan level 8 (types) and Pest
(tests, including architecture tests). CI also runs coverage, mutation tests and an install into a new Laravel app.
It needs no network and no Snowflake account. A fresh checkout should pass on the first run. If it does not, stop and
find out why before changing anything.

| Command | Does |
| --- | --- |
| `composer check` | The main CI checks |
| `composer test` | Pest only |
| `composer analyse` | PHPStan only |
| `composer fix` | Applies Pint's fixes, including method order |
| `composer test:live` | The opt-in test against a real account (section 8) |

`composer.lock` is not committed because this is a library; CI resolves fresh dependencies each run. Composer may print
`could not detect the root package version` locally. It is harmless.

To try the package inside a Laravel app, add a path repository to that app:

```bash
composer config repositories.snowflake path /path/to/laravel-snowflake
composer require "interworks/laravel-snowflake:@dev"
```

## 3. The map

```text
src/
├── Snowflake.php                  entry point; execute() sends the request and maps the response
├── Options.php                    timeouts, user agent suffix, response size limit
├── SnowflakeServiceProvider.php   registers config/snowflake-api.php, binds nothing
├── Auth/                          Authenticator interface, OAuthAccessToken, ProgrammaticAccessToken
├── Exceptions/                    SnowflakeException base and one class per failure kind
├── Http/
│   ├── SnowflakeConnector.php     Saloon connector: base URL, headers, auth, timeouts, transport safety
│   └── Requests/SubmitStatementRequest.php   POST /statements
├── Result/                        StatementResult and Column, the typed answer
├── Sql/                           ExternalOAuthIntegration: SQL text helpers, runs nothing
├── Statement/                     Statement builder, Binding, BindingType
└── Support/                       AccountUrl and Identifier validators
config/snowflake-api.php           the only config file
tests/
├── Arch/ArchTest.php              rules about the codebase itself
├── Fixtures/                      realistic Snowflake response bodies
├── Live/                          the opt-in real-account test
└── Unit/                          one file per area
```

## 4. Follow one call

```php
$result = $snowflake->execute(Statement::make('SELECT ...')->bind(1, BindingType::Fixed, '2026'));
```

1. **`Statement::toPayload()`** (`src/Statement/Statement.php`) builds the JSON body. Keys appear only when set, in a
   fixed order. Every builder method validates its input and returns a clone, so a `Statement` never changes after you
   hold it.
2. **`SubmitStatementRequest`** turns it into `POST /statements?nullable=true&requestId=<uuid v4>`. The request, not the
   connector, sets the HTTP timeout, because it depends on the statement: statement timeout plus the buffer.
3. **`SnowflakeConnector`** adds the base URL (`AccountUrl::apiBaseUrl()`), the headers (`Authorization: Bearer`,
   `X-Snowflake-Authorization-Token-Type`, `Accept`, `Content-Type`, `User-Agent`) and the transport settings: redirects
   off, TLS verification on, a progress callback that aborts oversized responses, and the Guzzle sender pinned. The token
   comes from `Authenticator::token()`, once per request.
4. **Saloon sends it.** HTTP error statuses do not throw. Only transport failures (connection, TLS, timeout) raise
   Saloon's `FatalRequestException`.
5. **`Snowflake::execute()`** times the call, then either converts a transport failure into a `TransportException` or
   decodes the body and branches on the status. `buildResult()` and its helpers validate a 200 body strictly;
   `failure()` maps every other status to its exception class.

Read `execute()` and the private helpers in `src/Snowflake.php` next to each other. That file is the behaviour contract.

## 5. The status mapping

| Snowflake answer | Result |
| --- | --- |
| 200, well-formed | `StatementResult` |
| 200, malformed | `TransportException` ("unexpected 200 body") |
| 202 | `StatementStillRunningException` |
| 401, 403 | `AuthenticationException` |
| 422 | `SqlException` |
| 429 | `RateLimitedException` (with `Retry-After`) |
| 408, 5xx, any other status, redirects | `TransportException` |
| Non-JSON body on a non-200 status | `TransportException` ("unreadable body") |
| Connection, TLS, timeout, oversized body | `TransportException` |

Snowflake's error bodies are read defensively: any field may be missing or the wrong type, and a fixed sentence is used
instead of a missing `message`.

## 6. The promises, and where each is enforced

Break one of these and you have changed the package's contract. A test must fail when you do.

| Promise | Enforced by | Tested in |
| --- | --- | --- |
| One statement per call | `Statement::parameter()` rejects `MULTI_STATEMENT_COUNT` in any case | `StatementTest` |
| A statement is never resent | No retry config; `allow_redirects => false` in the connector | `SnowflakeExecuteTest` ("never retries", "turns redirects off") |
| Tokens never leak | Closure storage and `#[SensitiveParameter]` in the authenticators | `AuthenticatorTest` |
| Exceptions carry no token, SQL or headers, and no previous exception | `Snowflake::describe()` copies only class and message; `SnowflakeException::redact()` masks `Bearer ...` | `SnowflakeExecuteTest` ("never leaks"), `ExceptionsTest` |
| Rows are untouched strings | `rowsFrom()` rejects any cell that is not a string or null | `SnowflakeExecuteTest` |
| Only trusted hosts | `AccountUrl` allows only https hosts under `snowflakecomputing.com` or `.cn` | `AccountUrlTest` |
| Only plain identifiers | `Identifier::isUnquoted()`, which `Identifier::assertUnquoted()` calls | `IdentifierTest` |
| No logging, debug output, curl or direct Guzzle | Architecture tests | `tests/Arch/ArchTest.php` |
| Bounded responses | `progress` callback in the connector | `SnowflakeExecuteTest` |
| Output is escaped in generated SQL | `ExternalOAuthIntegration::literal()` doubles quotes and backslashes | `ExternalOAuthIntegrationTest` |

## 7. Common changes

### Add a builder option to `Statement`

1. Add a private property and a builder method in `src/Statement/Statement.php`. Validate first, then `clone`, set, return.
2. Emit it from `toPayload()`, only when set, at a deliberate position in the key order.
3. Add cases to `StatementTest` (payload, rejection, clone leaves the original unchanged). The clone test is a dataset, so
   add a row there.
4. Document it in the README's statement builder section and `CHANGELOG.md`.

### Map a new HTTP status

1. Add a branch to `failure()` in `src/Snowflake.php`. Put the new exception in `src/Exceptions/`, extending
   `SnowflakeException`, and keep its constructor compatible with the base.
2. Add a fixture in `tests/Fixtures/` and a test next to the other mappings. Include the new exception in the "never
   leaks" dataset.
3. Update the tables in the README and in section 5 above.

### Add an authenticator

Implement `Auth\Authenticator` (`tokenType()` and `token()`). Hold the token in a closure, mark the constructor parameter
`#[SensitiveParameter]`, validate it, and implement `__debugInfo()`. Copy the existing tests in `AuthenticatorTest`,
especially the dump, serialisation and stack-trace checks. Key-pair signing needs its own dependency, so discuss it
before starting.

### Add a configuration option

1. Add the constructor parameter to `Options` (last, with a default) and validate it.
2. Read it in `Options::fromLaravelConfig()` and add the key to `config/snowflake-api.php` with an environment variable.
3. Use it in `SnowflakeConnector` or `SubmitStatementRequest`.
4. Add tests to `ServiceProviderTest` (default and override) and to `SnowflakeExecuteTest` (behaviour), then update the
   README's configuration table.

## 8. Testing toolbox

- **Faking Snowflake.** `snowflakeWith([...responses])` in `SnowflakeExecuteTest.php` builds a client with a `MockClient`
  and returns both. `lastPendingRequest($mock)` gives you the request that was sent: URL, headers, query, body and
  config. `jsonFixture('statement_422_sql_error')` loads a fixture from `tests/Fixtures/`.
- **Mock limits.** A mocked response never runs Guzzle, so redirect, TLS and progress-callback behaviour cannot be proven
  with `MockClient`. Assert the config instead, and for real behaviour start a throwaway server with
  `php -S 127.0.0.1:PORT router.php`, build a pending request from the real connector, point it at the local URL with
  `setUrl()` and send it with `$connector->sender()->send($pending)`. That is how the redirect and size-limit behaviour
  were verified.
- **A mocked fatal error records no response.** `assertSentCount()` will report 0 for a `FatalRequestException`.
- **Architecture tests.** Rules about functions use `->not->toBeUsed()`; rules about namespaces use
  `->not->toBeUsedIn()` with **one namespace per expectation**, because arrays of namespaces silently pass. After adding
  a rule, plant a violation in `src/` and confirm the test fails, then remove it.
- **The live test.** `tests/Live/LiveStatementTest.php` runs `SELECT CURRENT_USER() AS U, 1 AS ONE` against a real account.
  Export `SNOWFLAKE_LIVE=1`, `SNOWFLAKE_ACCOUNT_URL` and `SNOWFLAKE_PAT` in your shell, then `composer test:live`. Run
  it before every release. Never put those values in a file.

## 9. Gotchas that have already bitten once

- **Guzzle follows redirects by default**, including re-sending a `POST` on a 307. The connector turns it off.
- **`var_export()` ignores `__debugInfo()`**, so a token in a plain property leaks. The authenticators keep it in a
  closure.
- **An exception's stack trace is captured before its constructor runs**, so marking an exception's own constructor
  argument `#[SensitiveParameter]` does nothing. Caller frames are what matter.
- **Pest already defines `fixture()`** and dataset names are global across files. Use unique names.
- **Pint rewrites class layout.** Methods sort alphabetically after the constructor and enum cases sort alphabetically.
  Run `composer fix` rather than fighting it.
- **PHP's `$` matches before a trailing newline.** Anchor patterns with the `D` modifier when validating input
  (`AccountUrl`, `Identifier` do).
- **Testbench versions map to Laravel versions:** Testbench 10 is Laravel 12, 11 is Laravel 13. CI pins the matching one.
  Laravel 11 is excluded because Composer blocks every 11.x release over security advisories.
- **Error messages must not echo input.** Account URLs and tokens can carry credentials, so messages name the rule that
  failed, not the value.

## 10. Releasing

1. Update `CHANGELOG.md`: move `Unreleased` entries under the new version with a date.
2. Update `Snowflake::VERSION`, which feeds the User-Agent. Search for the old version string to catch the rest.
3. Confirm the `Tests passed` and `Lint passed` checks are green on `main`.
4. Run `composer test:live` by hand and keep the output.
5. Tag `vX.Y.Z` on `main`. Packagist picks it up from the repository hook.
6. In a scratch project, `composer require interworks/laravel-snowflake:X.Y.Z` and run the README quick start with
   a mock.

Follow semantic versioning: new optional behaviour is a minor release; changing a public signature, an exception mapping
or a default is a breaking change.
