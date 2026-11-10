# Review Standards

These are the standards the automated reviewer (`.github/workflows/code-review.yml`) applies, and what a person reviewing a pull request should check. CI already enforces style (Pint, PHPCS), types (PHPStan with Larastan at level 8), syntax, workflow syntax and the architecture tests, so review the behavior.

## What this package does

It submits one SQL statement to the Snowflake SQL API v2 with a caller-supplied credential, through Saloon, and returns a typed result or throws a typed exception. It is transport only: it never decides who may run what, never inspects SQL, never caches, never retries and never logs.

## Blocking issues

Ask for changes when the diff does one of these, and name the input or state that goes wrong.

1. **Exposes a token, header, request body or SQL text.** Exceptions never chain the underlying Saloon or Guzzle exception, because those carry the `Authorization` header, and their messages never contain a token, a header, the body or the SQL. Error messages never echo the account URL, which may carry userinfo. Every exception test asserts this.
2. **Weakens token masking.** Tokens stay in the masked closure (`Auth\Concealed`) inside the authenticators. Token, `Statement` and `Response` parameters are `#[SensitiveParameter]`. A plain property or a plain closure shows up in `var_export` or VarDumper.
3. **Sends a statement twice, or to another host.** There are no retries, and redirects stay off (`allow_redirects => false`), because a statement may write data. The connector pins the Guzzle sender so an app-level `saloon.default_sender` cannot reroute requests.
4. **Allows more than one statement per call.** The builder rejects `MULTI_STATEMENT_COUNT`.
5. **Loosens input validation.** The account URL must be `https`, on a host ending in `snowflakecomputing.com` or `snowflakecomputing.cn`, with no path, query, fragment, port, userinfo or backslash. Identifiers are unquoted only: `^[A-Za-z_][A-Za-z0-9_$]{0,254}$`. Generated SQL must not let a caller's value escape its literal or identifier.
6. **Changes the returned rows.** Rows are returned untouched as `string|null`, first partition only, and `partitionCount` says when more exist. Casting belongs to the consumer.
7. **Adds logging, caching, a facade, a container binding or static mutable state.** The package must stay safe under Octane and in queue workers.
8. **Breaks a supported version.** `composer.json` allows PHP 8.3 and later, and Laravel 12 and 13. Language features and framework calls must work on all of them. Env values arrive as strings, so a config value used as an int or bool must be cast.
9. **Breaks the public API without saying so.** Public classes and methods, exception types, config keys and the publish tag are used by downstream apps. A breaking change must be called out in the pull request description.
10. **Adds a test that cannot fail or calls the network.** Tests fake Snowflake with Saloon's `MockClient`, and only the `live` group may reach the network. A new rule needs a test that fails without it.

## Conventions

- A change in behavior updates `README.md` and `CHANGELOG.md` in the same pull request.
- Anything on the README roadmap is not built until it is asked for.

## Not blocking

Wording, comment polish, alternative designs, and test refactors are optional notes.
