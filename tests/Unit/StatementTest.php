<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Statement\Binding;
use InterWorks\SnowflakeApi\Statement\BindingType;
use InterWorks\SnowflakeApi\Statement\Statement;

it('omits every unset key from the payload', function (): void {
    expect(Statement::make('SELECT 1')->toPayload())->toBe(['statement' => 'SELECT 1', 'timeout' => 45]);
});

it('keeps the sql text exactly as supplied', function (): void {
    $sql = "  SELECT 1;\n";

    expect(Statement::make($sql)->sql())->toBe($sql);
});

it('builds the full payload in the documented key order', function (): void {
    $statement = Statement::make('SELECT region FROM governed.sales WHERE year = ? AND region = ?')
        ->bind(2, BindingType::Text, 'EMEA')
        ->bind(1, BindingType::Fixed, '2026')
        ->warehouse('ANALYTICS_WH')
        ->database('GOVERNED')
        ->schema('PUBLIC')
        ->role('ANALYST_ROLE')
        ->timeout(30)
        ->parameter('TIMEZONE', 'UTC')
        ->queryTag('app:user:12:page:/sales');

    expect($statement->toPayload())->toBe([
        'statement' => 'SELECT region FROM governed.sales WHERE year = ? AND region = ?',
        'timeout' => 30,
        'warehouse' => 'ANALYTICS_WH',
        'database' => 'GOVERNED',
        'schema' => 'PUBLIC',
        'role' => 'ANALYST_ROLE',
        'bindings' => [
            '1' => ['type' => 'FIXED', 'value' => '2026'],
            '2' => ['type' => 'TEXT', 'value' => 'EMEA'],
        ],
        'parameters' => ['QUERY_TAG' => 'app:user:12:page:/sales', 'TIMEZONE' => 'UTC'],
    ]);
});

it('encodes bindings as a json object keyed by the position string', function (): void {
    $payload = Statement::make('SELECT ?')->bind(1, BindingType::Fixed, '7')->toPayload();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($json)->toContain('"bindings":{"1":{"type":"FIXED","value":"7"}}');
});

it('emits json null for a null binding value', function (): void {
    $payload = Statement::make('SELECT ?')->bind(1, BindingType::Text, null)->toPayload();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($payload['bindings']['1']['value'])->toBeNull()
        ->and($json)->toContain('"value":null');
});

it('returns bindings ordered by position', function (): void {
    $bindings = Statement::make('SELECT ?, ?')
        ->bind(2, BindingType::Text, 'b')
        ->bind(1, BindingType::Text, 'a')
        ->bindings();

    expect($bindings)->toHaveCount(2)
        ->and($bindings[0])->toEqual(new Binding(1, BindingType::Text, 'a'))
        ->and($bindings[1])->toEqual(new Binding(2, BindingType::Text, 'b'));
});

it('defaults the timeout to 45 seconds', function (): void {
    expect(Statement::make('SELECT 1')->timeoutSeconds())->toBe(45)
        ->and(Statement::make('SELECT 1')->timeout(7)->timeoutSeconds())->toBe(7);
});

it('lets a later parameter call overwrite the same name', function (): void {
    $payload = Statement::make('SELECT 1')->parameter('TIMEZONE', 'UTC')->parameter('TIMEZONE', 'CET')->toPayload();

    expect($payload['parameters'])->toBe(['TIMEZONE' => 'CET']);
});

it('rejects invalid input', function (Closure $build): void {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'empty sql' => [fn () => Statement::make('')],
    'blank sql' => [fn () => Statement::make("  \n\t ")],
    'invalid utf-8 sql' => [fn () => Statement::make("SELECT '\xB1'")],
    'position 0' => [fn () => Statement::make('SELECT ?')->bind(0, BindingType::Text, 'a')],
    'negative position' => [fn () => Statement::make('SELECT ?')->bind(-1, BindingType::Text, 'a')],
    'duplicate position' => [
        fn () => Statement::make('SELECT ?')->bind(1, BindingType::Text, 'a')->bind(1, BindingType::Text, 'b'),
    ],
    'invalid utf-8 binding' => [fn () => Statement::make('SELECT ?')->bind(1, BindingType::Text, "\xB1")],
    'timeout 0' => [fn () => Statement::make('SELECT 1')->timeout(0)],
    'negative timeout' => [fn () => Statement::make('SELECT 1')->timeout(-5)],
    'multi statement count' => [fn () => Statement::make('SELECT 1')->parameter('MULTI_STATEMENT_COUNT', '2')],
    'multi statement count lowercase' => [
        fn () => Statement::make('SELECT 1')->parameter('multi_statement_count', '0'),
    ],
    'multi statement count mixed case' => [
        fn () => Statement::make('SELECT 1')->parameter('Multi_Statement_Count', '1'),
    ],
    'query tag via parameter' => [fn () => Statement::make('SELECT 1')->parameter('QUERY_TAG', 'x')],
    'query tag via parameter lowercase' => [fn () => Statement::make('SELECT 1')->parameter('query_tag', 'x')],
    'parameter name with space' => [fn () => Statement::make('SELECT 1')->parameter('MULTI_STATEMENT_COUNT ', '2')],
    'parameter name with newline' => [fn () => Statement::make('SELECT 1')->parameter("TIMEZONE\n", 'UTC')],
    'invalid utf-8 parameter value' => [fn () => Statement::make('SELECT 1')->parameter('TIMEZONE', "\xB1")],
    'query tag of 2001 characters' => [fn () => Statement::make('SELECT 1')->queryTag(str_repeat('a', 2001))],
    'query tag with newline' => [fn () => Statement::make('SELECT 1')->queryTag("a\nb")],
    'query tag with nul' => [fn () => Statement::make('SELECT 1')->queryTag("a\0b")],
    'query tag with delete' => [fn () => Statement::make('SELECT 1')->queryTag("a\x7fb")],
    'invalid utf-8 query tag' => [fn () => Statement::make('SELECT 1')->queryTag("\xB1")],
    'quoted warehouse' => [fn () => Statement::make('SELECT 1')->warehouse('"WH"')],
    'hyphenated database' => [fn () => Statement::make('SELECT 1')->database('MY-DB')],
    'dotted schema' => [fn () => Statement::make('SELECT 1')->schema('DB.PUBLIC')],
    'empty role' => [fn () => Statement::make('SELECT 1')->role('')],
]);

it('accepts a query tag of exactly 2000 characters', function (): void {
    $tag = str_repeat('a', 2000);

    expect(Statement::make('SELECT 1')->queryTag($tag)->toPayload()['parameters'])->toBe(['QUERY_TAG' => $tag]);
});

it('counts query tag length in characters rather than bytes', function (): void {
    $tag = str_repeat('é', 2000);

    expect(Statement::make('SELECT 1')->queryTag($tag)->toPayload()['parameters'])->toBe(['QUERY_TAG' => $tag]);
});

it('returns a clone from every builder method and leaves the receiver unchanged', function (Closure $mutate): void {
    $original = Statement::make('SELECT ?');
    $before = $original->toPayload();

    $changed = $mutate($original);

    expect($changed)->not->toBe($original)
        ->and($original->toPayload())->toBe($before)
        ->and($changed->toPayload())->not->toBe($before);
})->with([
    'bind' => [fn (Statement $s) => $s->bind(1, BindingType::Text, 'a')],
    'database' => [fn (Statement $s) => $s->database('DB')],
    'parameter' => [fn (Statement $s) => $s->parameter('TIMEZONE', 'UTC')],
    'queryTag' => [fn (Statement $s) => $s->queryTag('tag')],
    'role' => [fn (Statement $s) => $s->role('ROLE_X')],
    'schema' => [fn (Statement $s) => $s->schema('PUBLIC')],
    'timeout' => [fn (Statement $s) => $s->timeout(10)],
    'warehouse' => [fn (Statement $s) => $s->warehouse('WH')],
]);

it('never echoes a binding value or sql text in a rejection message', function (): void {
    try {
        Statement::make('SELECT ?')->bind(1, BindingType::Text, "secret-\xB1");
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain('secret')->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
});
