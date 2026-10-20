<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Auth\Authenticator;
use InterWorks\SnowflakeApi\Auth\OAuthAccessToken;
use InterWorks\SnowflakeApi\Auth\ProgrammaticAccessToken;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

const FAKE_TOKEN = 'fake-token-9f3a1c.payload.signature';

dataset('authenticators', [
    'oauth' => [fn () => new OAuthAccessToken(FAKE_TOKEN), 'OAUTH'],
    'pat' => [fn () => new ProgrammaticAccessToken(FAKE_TOKEN), 'PROGRAMMATIC_ACCESS_TOKEN'],
]);

it('reports its token type and returns the token', function (Closure $make, string $type): void {
    /** @var Authenticator $authenticator */
    $authenticator = $make();

    expect($authenticator->tokenType())->toBe($type)
        ->and($authenticator->token())->toBe(FAKE_TOKEN);
})->with('authenticators');

it('rejects an unusable token', function (string $class, string $token): void {
    new $class($token);
})->throws(InvalidArgumentException::class)->with([
    'oauth empty' => [OAuthAccessToken::class, ''],
    'oauth space' => [OAuthAccessToken::class, 'abc def'],
    'oauth newline' => [OAuthAccessToken::class, "abc\ndef"],
    'oauth trailing newline' => [OAuthAccessToken::class, "abc\n"],
    'oauth non-ascii' => [OAuthAccessToken::class, "abc\xC3\xA9"],
    'pat empty' => [ProgrammaticAccessToken::class, ''],
    'pat space' => [ProgrammaticAccessToken::class, 'abc def'],
    'pat carriage return' => [ProgrammaticAccessToken::class, "abc\r"],
]);

it('does not reveal the token through any dump or serialisation', function (Closure $make): void {
    $authenticator = $make();

    ob_start();
    var_dump($authenticator);
    $varDump = (string) ob_get_clean();

    $outputs = [
        'var_export' => var_export($authenticator, true),
        'print_r' => print_r($authenticator, true),
        'var_dump' => $varDump,
        'json_encode' => json_encode($authenticator, JSON_THROW_ON_ERROR),
    ];

    foreach ($outputs as $name => $output) {
        expect($output)->not->toContain(FAKE_TOKEN, "{$name} leaked the token");
    }

    expect($outputs['print_r'])->toContain('***')
        ->and($outputs['var_dump'])->toContain('***');
})->with('authenticators');

it('refuses to be serialised', function (Closure $make): void {
    serialize($make());
})->throws(Exception::class)->with('authenticators');

it('does not put the token in the message of a rejected construction', function (): void {
    try {
        new OAuthAccessToken("secret-value with space");
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('secret-value')->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});

it('keeps the token out of the stack trace arguments', function (): void {
    $trace = function (): string {
        try {
            new OAuthAccessToken('secret-value with space');
        } catch (InvalidArgumentException $e) {
            return $e->getTraceAsString() . print_r($e->getTrace(), true);
        }

        return '';
    };

    expect($trace())->not->toContain('secret-value');
});

it('does not reveal the token to VarDumper, an array cast or reflection of the closure', function (Closure $make): void {
    $authenticator = $make();

    $dumper = new CliDumper();
    $dumper->setColors(false);
    $dump = (string) $dumper->dump((new VarCloner())->cloneVar($authenticator), true);

    $closure = (new ReflectionProperty($authenticator, 'reveal'))->getValue($authenticator);
    $static = (new ReflectionFunction($closure))->getStaticVariables();

    expect($dump)->not->toContain(FAKE_TOKEN)
        ->and(print_r((array) $authenticator, true))->not->toContain(FAKE_TOKEN)
        ->and(implode('', array_map('bin2hex', $static)))->not->toContain(bin2hex(FAKE_TOKEN))
        ->and($static)->not->toContain(FAKE_TOKEN)
        ->and($authenticator->token())->toBe(FAKE_TOKEN);
})->with('authenticators');
