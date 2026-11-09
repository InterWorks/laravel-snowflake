<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Support\Identifier;

dataset('valid identifiers', [
    'short' => ['WH'],
    'underscore and dollar' => ['_x$1'],
    'lowercase' => ['analytics_wh'],
    '255 characters' => [str_repeat('A', 255)],
]);

dataset('invalid identifiers', [
    'empty' => [''],
    'leading digit' => ['1WH'],
    'leading dollar' => ['$WH'],
    'hyphen' => ['MY-WH'],
    'double quote' => ['"WH"'],
    'single quote' => ["WH'"],
    'space' => ['MY WH'],
    'dot' => ['DB.SCHEMA'],
    'trailing newline' => ["WH\n"],
    '256 characters' => [str_repeat('A', 256)],
]);

it('accepts a valid unquoted identifier and returns it unchanged', function (string $value): void {
    expect(Identifier::assertUnquoted($value, 'warehouse'))->toBe($value)
        ->and(Identifier::isUnquoted($value))->toBeTrue();
})->with('valid identifiers');

it('rejects an invalid identifier', function (string $value): void {
    Identifier::assertUnquoted($value, 'warehouse');
})->throws(InvalidArgumentException::class)->with('invalid identifiers');

it('answers no for an invalid identifier without throwing', function (string $value): void {
    expect(Identifier::isUnquoted($value))->toBeFalse();
})->with('invalid identifiers');

it('names the field in the message without echoing the value', function (): void {
    try {
        Identifier::assertUnquoted('bad-value', 'database');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('database')->and($e->getMessage())->not->toContain('bad-value');

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});
