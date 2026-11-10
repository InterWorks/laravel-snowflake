# CLAUDE.md for interworks/laravel-snowflake

A Snowflake SQL API v2 client for Laravel. Namespace `InterWorks\SnowflakeApi`, PHP `^8.3`, Laravel 12 and 13, Saloon v4
as the only HTTP layer.

Read `docs/WALKTHROUGH.md` first. It maps the code, follows one call end to end, and lists the recipes and gotchas.
`README.md` is the public contract: if behaviour changes, the README and `CHANGELOG.md` change in the same commit.

## What the package is

Transport only. It submits one SQL statement with a caller-supplied credential and returns a typed result or throws a
typed exception. It never decides who may run what, never inspects SQL, never caches, never retries and never logs.
Anything on the README roadmap is not built until it is asked for, even if it looks trivial.

## Commands

- Install: `composer install`
- The main CI checks: `composer check` (parallel-lint, Pint, PHPCS, PHPStan level 8, Pest including architecture tests).
  CI also runs coverage, mutation tests and an install into a new Laravel app. See `.github/workflows/`.
- Tests only: `composer test`. Style fix: `composer fix`.
- Live test: `composer test:live`, only with `SNOWFLAKE_LIVE=1`, `SNOWFLAKE_ACCOUNT_URL` and `SNOWFLAKE_PAT` exported in
  the shell. Never write those values into any file in this repository.

## Rules that hold unless the maintainers decide otherwise

Each rule has a reason. Do not weaken one to make a test pass.

- **One statement per call.** The builder rejects `MULTI_STATEMENT_COUNT`. This keeps authorization and error handling
  unambiguous.
- **A statement is never resent.** No retries, and redirects are off (`allow_redirects => false`), because a statement
  may write data and a redirect would resend it, possibly to another host.
- **Exceptions never chain** the underlying Saloon or Guzzle exception, and their messages never contain a token, a
  header, the request body or the SQL text. Those exceptions carry the `Authorization` header. Every exception test
  asserts this.
- **Tokens are held in a masked closure** (`Auth\Concealed`) inside the authenticators, and the constructor parameter is
  `#[SensitiveParameter]`. `var_export` ignores `__debugInfo` and VarDumper shows closure variables, which is why a plain
  property or a plain closure is not enough. `Statement` and `Response` parameters are `#[SensitiveParameter]` too.
- **Rows are returned untouched** as `string|null`, first partition only. `partitionCount` tells the consumer more
  exists. Casting is the consumer's policy.
- **Account URL:** `https`, host ending in `snowflakecomputing.com` or `snowflakecomputing.cn`, and no path, query,
  fragment, port, userinfo or backslash. Error messages never echo the URL, because it may carry userinfo.
- **Identifiers are unquoted only:** `^[A-Za-z_][A-Za-z0-9_$]{0,254}$`.
- **No logging, caching, facades or container bindings.** The package keeps no static mutable state, so it is safe under
  Octane and in queue workers. Architecture tests enforce most of this.
- **No Guzzle or `curl_*` calls outside Saloon.** The connector pins the Guzzle sender so an app-level
  `saloon.default_sender` cannot reroute requests.
- **Key-pair JWT signing is not in the core.** It arrives as its own authenticator, with its own dependency, when the
  roadmap reaches it.

## Code style

- PSR-12, 120-character lines including comments, `declare(strict_types=1)` in every file, return types everywhere.
- Every class `final` (the exception base is the one exception); value objects `readonly`.
- Methods in alphabetical order after the constructor, `use` statements alphabetical, no inline fully qualified class
  names. Pint enforces ordering: run `composer fix`.
- `catch (Throwable $e)`, never `Exception`. `elseif`, never `else if`, and an `else` branch whenever an `elseif` exists.
- Docblocks on every public member. Multi-line comments use `/** */`.
- No `dd`, `dump`, `var_dump`, `print_r` or `var_export` in `src/`. Architecture tests fail the build if one appears.

## Tests

- Pest 4 through Orchestra Testbench. Snowflake is faked with `Saloon\Http\Faking\MockClient`, attached via
  `$client->connector()->withMockClient($mock)`. Assert the sent request with `$mock->assertSent()` whenever the request
  shape matters.
- Fixture bodies live in `tests/Fixtures/`. Add a fixture rather than an inline array when a body is longer than five
  lines. Test helpers: `snowflakeWith()` in `SnowflakeExecuteTest.php`, `jsonFixture()` in `tests/Pest.php`.
- A test that needs the network, other than the `live` group, is a bug.
- A new rule needs a test that fails without it. For architecture tests, plant a violation and confirm the test fails.

## Git and releases

- Default branch `main`. Short-lived branches with a descriptive name, squash-merged through a pull request once CI and the
  automated review (`REVIEW.md`) pass.
- Commit messages in the imperative, one logical change per commit.
- Never commit `vendor/`, `.env`, `composer.lock` or any file containing a credential.
- Supported matrix: PHP 8.3, 8.4 and 8.5 on Laravel 12 and 13 (CI covers all six). Laravel 11 is excluded because every
  11.x release has published security advisories.
- Release: update `CHANGELOG.md`, make sure CI is green and the live test has been run by hand, tag `vX.Y.Z`. Packagist
  updates from the tag.
