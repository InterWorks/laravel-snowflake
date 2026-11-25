<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Support\ParentOrigin;

it('normalises an accepted origin', function (string $input, string $expected): void {
    expect(ParentOrigin::fromString($input)->toString())->toBe($expected);
})->with([
    'plain https' => ['https://portal.example.com', 'https://portal.example.com'],
    'trailing slash' => ['https://portal.example.com/', 'https://portal.example.com'],
    'upper case' => ['HTTPS://Portal.Example.COM', 'https://portal.example.com'],
    'custom port' => ['https://portal.example.com:8443', 'https://portal.example.com:8443'],
    'default https port' => ['https://portal.example.com:443', 'https://portal.example.com'],
    'single label host' => ['https://intranet', 'https://intranet'],
    'localhost over http' => ['http://localhost:3000', 'http://localhost:3000'],
    'localhost default http port' => ['http://localhost:80/', 'http://localhost'],
    'localhost over https' => ['https://localhost', 'https://localhost'],
]);

it('refuses a value that is not an origin', function (string $input): void {
    ParentOrigin::fromString($input);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [''],
    'no scheme' => ['portal.example.com'],
    'http on a public host' => ['http://portal.example.com'],
    'other scheme' => ['ftp://portal.example.com'],
    'javascript' => ['javascript:alert(1)'],
    'path' => ['https://portal.example.com/page'],
    'query' => ['https://portal.example.com?x=1'],
    'fragment' => ['https://portal.example.com#x'],
    'userinfo' => ['https://user:pass@portal.example.com'],
    'wildcard' => ['https://*.example.com'],
    'backslash' => ['https://portal.example.com\\'],
    'whitespace' => ['https://portal.example.com '],
    'newline' => ["https://portal.example.com\n"],
    'port zero' => ['https://portal.example.com:0'],
    'port too high' => ['https://portal.example.com:65536'],
    'empty label' => ['https://portal..example.com'],
    'label starting with a hyphen' => ['https://-portal.example.com'],
    'underscore' => ['https://por_tal.example.com'],
]);

it('never echoes the rejected value in the message', function (): void {
    try {
        ParentOrigin::fromString('https://user:secret-password@portal.example.com');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('secret-password')
            ->and($e->getMessage())->not->toContain('portal.example.com');

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});
