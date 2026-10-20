<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\AuthenticationException;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Exceptions\RateLimitedException;
use InterWorks\SnowflakeApi\Exceptions\SnowflakeException;
use InterWorks\SnowflakeApi\Exceptions\SqlException;
use InterWorks\SnowflakeApi\Exceptions\StatementStillRunningException;
use InterWorks\SnowflakeApi\Exceptions\TransportException;

it('extends the documented base classes', function (): void {
    expect(new AuthenticationException('x'))->toBeInstanceOf(SnowflakeException::class)
        ->and(new RateLimitedException('x'))->toBeInstanceOf(SnowflakeException::class)
        ->and(new SqlException('x'))->toBeInstanceOf(SnowflakeException::class)
        ->and(new StatementStillRunningException('x'))->toBeInstanceOf(SnowflakeException::class)
        ->and(new TransportException('x'))->toBeInstanceOf(SnowflakeException::class)
        ->and(new SnowflakeException('x'))->toBeInstanceOf(RuntimeException::class)
        ->and(new InvalidArgumentException('x'))->toBeInstanceOf(InvalidArgumentException::class)
        ->and(new InvalidArgumentException('x'))->toBeInstanceOf(\InvalidArgumentException::class);
});

it('carries the base properties', function (): void {
    $e = new SnowflakeException('boom', 500, '000123', '01b2');

    expect($e->getMessage())->toBe('boom')
        ->and($e->httpStatus)->toBe(500)
        ->and($e->snowflakeCode)->toBe('000123')
        ->and($e->statementHandle)->toBe('01b2')
        ->and($e->getPrevious())->toBeNull();
});

it('defaults every optional property to null', function (): void {
    $e = new TransportException('boom');

    expect($e->httpStatus)->toBeNull()
        ->and($e->snowflakeCode)->toBeNull()
        ->and($e->statementHandle)->toBeNull();
});

it('carries the subclass properties', function (): void {
    $rate = new RateLimitedException('slow down', 429, 17);
    $sql = new SqlException('bad sql', 422, '002003', '01b2', '42S02');
    $running = new StatementStillRunningException('running', 202, '333334', '01b2', '/api/v2/statements/01b2');

    expect($rate->retryAfterSeconds)->toBe(17)
        ->and($rate->httpStatus)->toBe(429)
        ->and($sql->sqlState)->toBe('42S02')
        ->and($sql->snowflakeCode)->toBe('002003')
        ->and($sql->statementHandle)->toBe('01b2')
        ->and($running->statementStatusUrl)->toBe('/api/v2/statements/01b2')
        ->and($running->httpStatus)->toBe(202);
});

it('redacts bearer credentials from any message', function (string $message, string $expected): void {
    $e = new TransportException($message);

    expect($e->getMessage())->toBe($expected);
})->with([
    'plain' => ['Authorization: Bearer s3cret.jwt.value failed', 'Authorization: Bearer *** failed'],
    'lowercase' => ['bearer s3cret', 'Bearer ***'],
    'multiple spaces' => ["Bearer   s3cret\tnext", "Bearer ***\tnext"],
    'twice' => ['Bearer s3cret and Bearer s3cret', 'Bearer *** and Bearer ***'],
    'jwt in sql error' => ["unexpected 'eyJhbGciOi.eyJzdWIiOiIx-_.sig_nature'", "unexpected 'eyJ***'"],
    'untouched' => ['SQL compilation error: object does not exist', 'SQL compilation error: object does not exist'],
]);
