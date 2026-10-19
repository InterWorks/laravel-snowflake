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
- SQL helpers for External OAuth: `CREATE SECURITY INTEGRATION` and `SYSTEM$VERIFY_EXTERNAL_OAUTH_TOKEN`.
- Laravel service provider with one config file.
- Supports PHP 8.3 and 8.4 on Laravel 12 and 13. Laravel 11 is excluded because every 11.x release has published
  security advisories.

### Security

- Transfers are aborted past `max_response_bytes` (default 64 MiB, counted on the wire).
- Redirects are disabled, so a statement is never resent and a `Location` header is never followed.
- Certificate verification is stated explicitly, and the connector is pinned to the Guzzle sender so a
  `saloon.default_sender` override cannot route requests through a client that records headers.
