# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - Unreleased

### Added

- Synchronous submission of one SQL statement to the Snowflake SQL API v2, returning the first result partition.
- OAuth access token and Programmatic Access Token authenticators.
- Immutable statement builder with positional bindings, warehouse, database, schema, role, timeout, query tag and
  session parameters.
- Typed statement result with columns, rows, row count, partition count, statement handle and elapsed time.
- Exception hierarchy mapping Snowflake HTTP statuses to typed exceptions that never expose a token.
- SQL helpers for External OAuth: `CREATE SECURITY INTEGRATION` with a JWKS URL or a pasted RSA public key, optionally
  as `CREATE OR REPLACE` and with an `EXTERNAL_OAUTH_ALLOWED_ROLES_LIST`, and `SYSTEM$VERIFY_EXTERNAL_OAUTH_TOKEN`.
- `Identifier::isUnquoted()` and `Identifier::assertUnquoted()` to check unquoted identifiers, for example in form
  validation.
- Laravel service provider with one config file.
- Supports PHP 8.3 and 8.4 on Laravel 12 and 13. Laravel 11 is excluded because every 11.x release has published
  security advisories.

### Security

- Tokens are held XOR-masked with a random key, so Symfony VarDumper (`dd()`, error pages), array casts and reflection
  do not reveal them.
- `Snowflake::execute()` and the request constructor mark the statement `#[SensitiveParameter]`, keeping SQL and bound
  values out of stack trace arguments.
- Exception messages mask JWT-shaped strings that Snowflake echoes back, as well as `Bearer` credentials.
- The response size limit also applies to the decoded body, so a compressed response cannot expand past it.
- The pasted-key builder accepts only one PEM `PUBLIC KEY` block or its bare base64 body, so a pasted private key or
  certificate is refused rather than written into the setup SQL and Snowflake's query history.
- Account URL hosts with empty labels or labels starting or ending in a hyphen are rejected.
- Transfers are aborted past `max_response_bytes` (default 64 MiB, counted on the wire and again on the decoded body).
- Redirects are disabled, so a statement is never resent and a `Location` header is never followed.
- Certificate verification is stated explicitly, and the connector is pinned to the Guzzle sender so a
  `saloon.default_sender` override cannot route requests through a client that records headers.
