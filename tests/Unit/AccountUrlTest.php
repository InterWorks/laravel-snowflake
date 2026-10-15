<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Support\AccountUrl;

it('accepts a valid account url and normalises it', function (string $input, string $expected): void {
    $url = AccountUrl::fromString($input);

    expect($url->toString())->toBe($expected)
        ->and($url->apiBaseUrl())->toBe($expected . '/api/v2');
})->with([
    'org-account' => [
        'https://myorg-myaccount.snowflakecomputing.com',
        'https://myorg-myaccount.snowflakecomputing.com',
    ],
    'locator and region' => [
        'https://xy12345.eu-central-1.snowflakecomputing.com',
        'https://xy12345.eu-central-1.snowflakecomputing.com',
    ],
    'privatelink' => [
        'https://acme.privatelink.snowflakecomputing.com',
        'https://acme.privatelink.snowflakecomputing.com',
    ],
    'china' => ['https://acme.snowflakecomputing.cn', 'https://acme.snowflakecomputing.cn'],
    'uppercase host' => [
        'https://MyOrg-MyAccount.SnowflakeComputing.COM',
        'https://myorg-myaccount.snowflakecomputing.com',
    ],
    'trailing slash' => ['https://acme.snowflakecomputing.com/', 'https://acme.snowflakecomputing.com'],
    'uppercase scheme' => ['HTTPS://acme.snowflakecomputing.com', 'https://acme.snowflakecomputing.com'],
]);

it('rejects an unacceptable account url', function (string $input): void {
    AccountUrl::fromString($input);
})->throws(InvalidArgumentException::class)->with([
    'http scheme' => ['http://acme.snowflakecomputing.com'],
    'no scheme' => ['acme.snowflakecomputing.com'],
    'empty' => [''],
    'path' => ['https://acme.snowflakecomputing.com/api/v2'],
    'query string' => ['https://acme.snowflakecomputing.com?x=1'],
    'query string after slash' => ['https://acme.snowflakecomputing.com/?x=1'],
    'fragment' => ['https://acme.snowflakecomputing.com#frag'],
    'userinfo' => ['https://user:secret@acme.snowflakecomputing.com'],
    'userinfo host trick' => ['https://acme.snowflakecomputing.com@evil.example'],
    'backslash' => ['https://evil.example\\@acme.snowflakecomputing.com'],
    'suffix trick' => ['https://snowflakecomputing.com.evil.example'],
    'prefix trick' => ['https://evilsnowflakecomputing.com'],
    'bare domain' => ['https://snowflakecomputing.com'],
    'other host' => ['https://example.com'],
    'port' => ['https://acme.snowflakecomputing.com:8443'],
    'leading dot' => ['https://.snowflakecomputing.com'],
    'trailing newline' => ["https://acme.snowflakecomputing.com\n"],
    'embedded space' => ['https://acme .snowflakecomputing.com'],
    'double slash path' => ['https://acme.snowflakecomputing.com//'],
]);

it('never echoes the rejected url in the message', function (): void {
    try {
        AccountUrl::fromString('https://user:hunter2-secret@acme.snowflakecomputing.com');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('hunter2-secret')
            ->and($e->getMessage())->not->toContain('acme');

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});
