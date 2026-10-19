<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Auth\Authenticator;
use InterWorks\SnowflakeApi\Auth\OAuthAccessToken;
use InterWorks\SnowflakeApi\Auth\ProgrammaticAccessToken;
use InterWorks\SnowflakeApi\Exceptions\AuthenticationException;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Exceptions\RateLimitedException;
use InterWorks\SnowflakeApi\Exceptions\SnowflakeException;
use InterWorks\SnowflakeApi\Exceptions\SqlException;
use InterWorks\SnowflakeApi\Exceptions\StatementStillRunningException;
use InterWorks\SnowflakeApi\Exceptions\TransportException;
use InterWorks\SnowflakeApi\Http\Requests\SubmitStatementRequest;
use InterWorks\SnowflakeApi\Options;
use InterWorks\SnowflakeApi\Result\Column;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\Statement\BindingType;
use InterWorks\SnowflakeApi\Statement\Statement;
use InterWorks\SnowflakeApi\Support\AccountUrl;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Senders\GuzzleSender;

const EXECUTE_TOKEN = 'exec-token-77c1d2.payload.signature';
const ACCOUNT_URL = 'https://myorg-myaccount.snowflakecomputing.com';

/**
 * A client whose connector answers with the given mock responses.
 *
 * @param array<MockResponse|callable> $responses
 * @return array{0: Snowflake, 1: MockClient}
 */
function snowflakeWith(array $responses, ?Authenticator $authenticator = null, ?Options $options = null): array
{
    $client = new Snowflake(
        AccountUrl::fromString(ACCOUNT_URL),
        $authenticator ?? new OAuthAccessToken(EXECUTE_TOKEN),
        $options ?? new Options(),
    );
    $mock = new MockClient($responses);
    $client->connector()->withMockClient($mock);

    return [$client, $mock];
}

function lastPendingRequest(MockClient $mock): PendingRequest
{
    $pending = $mock->getLastPendingRequest();

    if ($pending === null) {
        throw new RuntimeException('No request was sent.');
    }

    return $pending;
}

dataset('executeAuthenticators', [
    'oauth' => [fn () => new OAuthAccessToken(EXECUTE_TOKEN), 'OAUTH'],
    'pat' => [fn () => new ProgrammaticAccessToken(EXECUTE_TOKEN), 'PROGRAMMATIC_ACCESS_TOKEN'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// Request shape
// ---------------------------------------------------------------------------------------------------------------------

it('sends the documented request', function (Closure $makeAuthenticator, string $tokenType): void {
    [$client, $mock] = snowflakeWith(
        [MockResponse::make(jsonFixture('statement_200_single_partition'))],
        $makeAuthenticator(),
        new Options(userAgentSuffix: 'curator/1.2'),
    );

    $client->execute(Statement::make('SELECT 1'));

    $mock->assertSent(SubmitStatementRequest::class);
    $mock->assertSentCount(1);

    $pending = lastPendingRequest($mock);
    $headers = $pending->headers()->all();

    expect($pending->getMethod()->value)->toBe('POST')
        ->and($pending->getUrl())->toBe(ACCOUNT_URL . '/api/v2/statements')
        ->and($pending->query()->get('nullable'))->toBe('true')
        ->and($pending->query()->get('requestId'))->toMatch(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'
        )
        ->and($headers['Authorization'])->toBe('Bearer ' . EXECUTE_TOKEN)
        ->and($headers['X-Snowflake-Authorization-Token-Type'])->toBe($tokenType)
        ->and($headers['Content-Type'])->toBe('application/json')
        ->and($headers['Accept'])->toBe('application/json')
        ->and($headers['User-Agent'])->toBe('interworks-laravel-snowflake/' . Snowflake::VERSION . ' curator/1.2');
})->with('executeAuthenticators');

it('sends the plain user agent when no suffix is set', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    $client->execute(Statement::make('SELECT 1'));

    expect(lastPendingRequest($mock)->headers()->get('User-Agent'))
        ->toBe('interworks-laravel-snowflake/' . Snowflake::VERSION);
});

it('generates a fresh request id for every call', function (): void {
    [$client, $mock] = snowflakeWith([
        MockResponse::make(jsonFixture('statement_200_single_partition')),
        MockResponse::make(jsonFixture('statement_200_single_partition')),
    ]);

    $client->execute(Statement::make('SELECT 1'));
    $first = lastPendingRequest($mock)->query()->get('requestId');
    $client->execute(Statement::make('SELECT 1'));
    $second = lastPendingRequest($mock)->query()->get('requestId');

    expect($first)->not->toBe($second);
});

it('sends the full statement payload as the json body', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    $statement = Statement::make('SELECT region, SUM(revenue) FROM governed.sales WHERE year = ? GROUP BY region')
        ->bind(1, BindingType::Fixed, '2026')
        ->warehouse('ANALYTICS_WH')
        ->database('GOVERNED')
        ->schema('PUBLIC')
        ->role('CURATOR_VIEWER')
        ->timeout(30)
        ->queryTag('curator:user:12:page:/sales');

    $client->execute($statement);

    $body = lastPendingRequest($mock)->body();

    expect($body?->all())->toBe($statement->toPayload())
        ->and(json_decode((string) $body, true))->toBe([
            'statement' => 'SELECT region, SUM(revenue) FROM governed.sales WHERE year = ? GROUP BY region',
            'timeout' => 30,
            'warehouse' => 'ANALYTICS_WH',
            'database' => 'GOVERNED',
            'schema' => 'PUBLIC',
            'role' => 'CURATOR_VIEWER',
            'bindings' => ['1' => ['type' => 'FIXED', 'value' => '2026']],
            'parameters' => ['QUERY_TAG' => 'curator:user:12:page:/sales'],
        ])
        ->and((string) $body)->toContain('"bindings":{"1":{"type":"FIXED","value":"2026"}}');
});

it('never sends multi statement, async or retry settings', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    $client->execute(Statement::make('SELECT 1')->parameter('TIMEZONE', 'UTC'));

    $body = (string) lastPendingRequest($mock)->body();

    expect(strtolower($body))->not->toContain('multi_statement_count')
        ->and($body)->not->toContain('"async"')
        ->and($body)->not->toContain('"retry"');
});

it('sets the http timeout from the statement timeout plus the buffer', function (): void {
    [$client, $mock] = snowflakeWith(
        [MockResponse::make(jsonFixture('statement_200_single_partition'))],
        null,
        new Options(httpTimeoutBufferSeconds: 7, connectTimeoutSeconds: 3),
    );

    $client->execute(Statement::make('SELECT 1')->timeout(20));

    $config = lastPendingRequest($mock)->config()->all();

    expect($config['timeout'])->toBe(27)
        ->and($config['connect_timeout'])->toBe(3);
});

it('uses the default timeouts of 45 plus 5 and 10', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    $client->execute(Statement::make('SELECT 1'));

    $config = lastPendingRequest($mock)->config()->all();

    expect($config['timeout'])->toBe(50)
        ->and($config['connect_timeout'])->toBe(10);
});

// ---------------------------------------------------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------------------------------------------------

it('maps a single partition 200 to a result', function (): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    $result = $client->execute(Statement::make('SELECT region, revenue FROM t'));

    expect($result->statementHandle)->toBe('01b2c3d4-0000-1111-2222-333344445555')
        ->and($result->columns)->toEqual([
            new Column('REGION', 'text', true, null, null),
            new Column('REVENUE', 'fixed', true, 38, 2),
        ])
        ->and($result->rows)->toBe([['EMEA', '1250.50'], ['APAC', '980.00'], ['AMER', null]])
        ->and($result->numRows)->toBe(3)
        ->and($result->partitionCount)->toBe(1)
        ->and($result->hasMorePartitions())->toBeFalse()
        ->and($result->sqlState)->toBe('00000')
        ->and($result->message)->toBe('Statement executed successfully.')
        ->and($result->elapsedMilliseconds)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($result->toArray())->toBe([
            'columns' => [['name' => 'REGION', 'type' => 'text'], ['name' => 'REVENUE', 'type' => 'fixed']],
            'rows' => [['EMEA', '1250.50'], ['APAC', '980.00'], ['AMER', null]],
            'numRows' => 3,
            'partitionCount' => 1,
        ]);
});

it('returns only the first partition and reports the partition count', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_three_partitions'))]);

    $result = $client->execute(Statement::make('SELECT region, revenue FROM t'));

    expect($result->rows)->toHaveCount(3)
        ->and($result->numRows)->toBe(25000)
        ->and($result->partitionCount)->toBe(3)
        ->and($result->hasMorePartitions())->toBeTrue();

    $mock->assertSentCount(1);
});

it('returns values untouched as strings or null', function (): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    foreach ($client->execute(Statement::make('SELECT 1'))->rows as $row) {
        foreach ($row as $cell) {
            expect($cell === null || is_string($cell))->toBeTrue();
        }
    }
});

it('rejects a 200 body that is not the expected shape', function (string $body): void {
    [$client] = snowflakeWith([MockResponse::make($body)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe('Snowflake returned an unexpected 200 body.')
            ->and($e->httpStatus)->toBe(200)
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected a TransportException.');
})->with([
    'not json' => ['<html>proxy page</html>'],
    'empty' => [''],
    'json scalar' => ['42'],
    'empty object' => ['{}'],
    'no data' => ['{"resultSetMetaData":{"numRows":1,"partitionInfo":[{}],"rowType":[]}}'],
    'data not array' => ['{"resultSetMetaData":{"numRows":1,"partitionInfo":[{}],"rowType":[]},"data":"x"}'],
    'no metadata' => ['{"data":[]}'],
    'no row type' => ['{"resultSetMetaData":{"numRows":0,"partitionInfo":[{}]},"data":[]}'],
    'row type not array' => ['{"resultSetMetaData":{"numRows":0,"partitionInfo":[{}],"rowType":"x"},"data":[]}'],
    'no partition info' => ['{"resultSetMetaData":{"numRows":0,"rowType":[]},"data":[]}'],
    'partition info not array' => ['{"resultSetMetaData":{"numRows":0,"partitionInfo":1,"rowType":[]},"data":[]}'],
    'no num rows' => ['{"resultSetMetaData":{"partitionInfo":[{}],"rowType":[]},"data":[]}'],
    'column without name' => [
        '{"resultSetMetaData":{"numRows":0,"partitionInfo":[{}],"rowType":[{"type":"text"}]},"data":[]}',
    ],
    'column without type' => [
        '{"resultSetMetaData":{"numRows":0,"partitionInfo":[{}],"rowType":[{"name":"A"}]},"data":[]}',
    ],
    'row not a list' => [
        '{"resultSetMetaData":{"numRows":1,"partitionInfo":[{}],"rowType":[{"name":"A","type":"text"}]},'
        . '"data":[{"A":"x"}]}',
    ],
    'row with wrong width' => [
        '{"resultSetMetaData":{"numRows":1,"partitionInfo":[{}],"rowType":[{"name":"A","type":"text"}]},'
        . '"data":[["x","y"]]}',
    ],
    'non string cell' => [
        '{"resultSetMetaData":{"numRows":1,"partitionInfo":[{}],"rowType":[{"name":"A","type":"text"}]},'
        . '"data":[[5]]}',
    ],
]);

it('accepts an empty result set', function (): void {
    $body = '{"resultSetMetaData":{"numRows":0,"partitionInfo":[{"rowCount":0}],"rowType":'
        . '[{"name":"A","type":"text","nullable":false}]},"data":[],"statementHandle":"h1"}';
    [$client] = snowflakeWith([MockResponse::make($body)]);

    $result = $client->execute(Statement::make('SELECT a FROM t WHERE 1 = 0'));

    expect($result->rows)->toBe([])
        ->and($result->numRows)->toBe(0)
        ->and($result->columns[0]->nullable)->toBeFalse()
        ->and($result->message)->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// Error mapping
// ---------------------------------------------------------------------------------------------------------------------

it('maps 202 to StatementStillRunningException', function (): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_202'), 202)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (StatementStillRunningException $e) {
        expect($e->httpStatus)->toBe(202)
            ->and($e->snowflakeCode)->toBe('333334')
            ->and($e->statementHandle)->toBe('01b2c3d4-0000-1111-2222-333344445555')
            ->and($e->statementStatusUrl)->toBe('/api/v2/statements/01b2c3d4-0000-1111-2222-333344445555')
            ->and($e->getMessage())->toContain('Asynchronous execution in progress');

        return;
    }

    $this->fail('Expected a StatementStillRunningException.');
});

it('maps 401 and 403 to AuthenticationException', function (string $fixture, int $status, string $code): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture($fixture), $status)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (AuthenticationException $e) {
        expect($e->httpStatus)->toBe($status)
            ->and($e->snowflakeCode)->toBe($code);

        return;
    }

    $this->fail('Expected an AuthenticationException.');
})->with([
    '401' => ['statement_401', 401, '390303'],
    '403' => ['statement_403', 403, '390189'],
]);

it('maps 422 to SqlException', function (): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_422_sql_error'), 422)]);

    try {
        $client->execute(Statement::make('SELECT * FROM governed.sales'));
    } catch (SqlException $e) {
        expect($e->httpStatus)->toBe(422)
            ->and($e->snowflakeCode)->toBe('002003')
            ->and($e->sqlState)->toBe('42S02')
            ->and($e->statementHandle)->toBe('01b2c3d4-0000-1111-2222-333344445555')
            ->and($e->getMessage())->toContain('does not exist or not authorized')
            ->and($e->getMessage())->not->toContain('SELECT * FROM');

        return;
    }

    $this->fail('Expected a SqlException.');
});

it('maps 429 to RateLimitedException and reads Retry-After', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture('statement_429'), 429, ['Retry-After' => '12'])]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (RateLimitedException $e) {
        expect($e->httpStatus)->toBe(429)
            ->and($e->retryAfterSeconds)->toBe(12)
            ->and($e->snowflakeCode)->toBe('390190');
        $mock->assertSentCount(1);

        return;
    }

    $this->fail('Expected a RateLimitedException.');
});

it('reads a missing or unusable Retry-After as null', function (array $headers): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_429'), 429, $headers)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (RateLimitedException $e) {
        expect($e->retryAfterSeconds)->toBeNull();

        return;
    }

    $this->fail('Expected a RateLimitedException.');
})->with([
    'missing' => [[]],
    'http date' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']],
    'negative' => [['Retry-After' => '-5']],
    'decimal' => [['Retry-After' => '1.5']],
    'huge' => [['Retry-After' => '99999999999999999999']],
]);

it('maps every other status to TransportException', function (int $status, string $fixture): void {
    [$client, $mock] = snowflakeWith([MockResponse::make(jsonFixture($fixture), $status)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->httpStatus)->toBe($status);
        $mock->assertSentCount(1);

        return;
    }

    $this->fail('Expected a TransportException.');
})->with([
    '500' => [500, 'statement_500'],
    '503' => [503, 'statement_500'],
    '408' => [408, 'statement_500'],
    '400' => [400, 'statement_500'],
    '404' => [404, 'statement_500'],
    '302' => [302, 'statement_500'],
]);

it('uses fixed sentences when the body has no message', function (int $status, string $class, string $sentence): void {
    [$client] = snowflakeWith([MockResponse::make('{}', $status)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (SnowflakeException $e) {
        expect($e)->toBeInstanceOf($class)->and($e->getMessage())->toBe($sentence);

        return;
    }

    $this->fail('Expected a SnowflakeException.');
})->with([
    '202' => [202, StatementStillRunningException::class, 'Snowflake is still running the statement.'],
    '401' => [401, AuthenticationException::class, 'Snowflake rejected the credentials.'],
    '403' => [403, AuthenticationException::class, 'Snowflake rejected the credentials.'],
    '422' => [422, SqlException::class, 'Snowflake could not run the statement.'],
    '429' => [429, RateLimitedException::class, 'Snowflake rate limit reached.'],
    '500' => [500, TransportException::class, 'Snowflake did not answer.'],
]);

it('reports an unreadable body on an error status', function (int $status, string $body): void {
    [$client] = snowflakeWith([MockResponse::make($body, $status)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe(sprintf('Snowflake returned status %d with an unreadable body.', $status))
            ->and($e->httpStatus)->toBe($status);

        return;
    }

    $this->fail('Expected a TransportException.');
})->with([
    '502 html' => [502, '<html>Bad Gateway</html>'],
    '500 empty' => [500, ''],
    '401 html' => [401, '<html>denied</html>'],
    '422 scalar' => [422, '"nope"'],
]);

it('ignores message and code fields of the wrong type', function (): void {
    [$client] = snowflakeWith([MockResponse::make('{"message":["x"],"code":{"a":1},"statementHandle":5}', 500)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe('Snowflake did not answer.')->and($e->snowflakeCode)->toBeNull();

        return;
    }

    $this->fail('Expected a TransportException.');
});

it('accepts an integer code', function (): void {
    [$client] = snowflakeWith([MockResponse::make('{"message":"m","code":390303}', 401)]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (AuthenticationException $e) {
        expect($e->snowflakeCode)->toBe('390303');

        return;
    }

    $this->fail('Expected an AuthenticationException.');
});

// ---------------------------------------------------------------------------------------------------------------------
// Transport failures
// ---------------------------------------------------------------------------------------------------------------------

it('maps a Saloon FatalRequestException to TransportException', function (): void {
    $response = MockResponse::make()->throw(
        fn (PendingRequest $pending) => new FatalRequestException(
            new RuntimeException('cURL error 28: Operation timed out after 50001 milliseconds'),
            $pending
        )
    );
    [$client] = snowflakeWith([$response]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe(
            'Could not reach Snowflake: RuntimeException: cURL error 28: Operation timed out after 50001 milliseconds'
        )
            ->and($e->httpStatus)->toBeNull()
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected a TransportException.');
});

it('redacts a bearer token that appears in the underlying exception message', function (): void {
    $response = MockResponse::make()->throw(
        fn (PendingRequest $pending) => new FatalRequestException(
            new RuntimeException('Request failed. Authorization: Bearer ' . EXECUTE_TOKEN . ' was refused'),
            $pending
        )
    );
    [$client] = snowflakeWith([$response]);

    try {
        $client->execute(Statement::make('SELECT 1'));
    } catch (TransportException $e) {
        expect($e->getMessage())->toBe('Could not reach Snowflake: RuntimeException: Request failed. '
            . 'Authorization: Bearer *** was refused');

        return;
    }

    $this->fail('Expected a TransportException.');
});

it('wraps any other failure while sending in a TransportException', function (): void {
    [$client] = snowflakeWith([fn () => throw new LogicException('boom')]);

    expect(fn () => $client->execute(Statement::make('SELECT 1')))->toThrow(TransportException::class);
});

// ---------------------------------------------------------------------------------------------------------------------
// No credential or SQL leaks, no chaining
// ---------------------------------------------------------------------------------------------------------------------

it('never leaks the token, the sql or a previous exception from any thrown exception', function (
    Closure $makeResponse
): void {
    [$client] = snowflakeWith([$makeResponse()]);

    try {
        $client->execute(Statement::make('SELECT secret_column FROM top_secret_table'));
    } catch (SnowflakeException $e) {
        foreach ([$e->getMessage(), (string) $e, $e->getTraceAsString()] as $text) {
            expect($text)->not->toContain(EXECUTE_TOKEN)
                ->and($text)->not->toContain('Bearer exec-token')
                ->and($text)->not->toContain('top_secret_table');
        }

        expect($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected a SnowflakeException.');
})->with([
    '202' => [fn () => MockResponse::make(jsonFixture('statement_202'), 202)],
    '401' => [fn () => MockResponse::make(jsonFixture('statement_401'), 401)],
    '403' => [fn () => MockResponse::make(jsonFixture('statement_403'), 403)],
    '422' => [fn () => MockResponse::make(jsonFixture('statement_422_sql_error'), 422)],
    '429' => [fn () => MockResponse::make(jsonFixture('statement_429'), 429)],
    '500' => [fn () => MockResponse::make(jsonFixture('statement_500'), 500)],
    'unreadable body' => [fn () => MockResponse::make('<html></html>', 502)],
    'unexpected 200' => [fn () => MockResponse::make('{}', 200)],
    'fatal' => [
        fn () => MockResponse::make()->throw(
            fn (PendingRequest $pending) => new FatalRequestException(
                new RuntimeException('Authorization: Bearer ' . EXECUTE_TOKEN),
                $pending
            )
        ),
    ],
]);

it('never retries a statement', function (int $status): void {
    [$client, $mock] = snowflakeWith([
        MockResponse::make('{}', $status),
        MockResponse::make(jsonFixture('statement_200_single_partition')),
    ]);

    try {
        $client->execute(Statement::make('INSERT INTO t VALUES (1)'));
    } catch (SnowflakeException) {
        // expected
    }

    $mock->assertSentCount(1);
})->with([500, 502, 503, 429, 408]);

// ---------------------------------------------------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------------------------------------------------

it('builds a client from a plain url and rejects a bad one', function (): void {
    $client = Snowflake::make(ACCOUNT_URL, new OAuthAccessToken(EXECUTE_TOKEN));

    expect($client->connector()->resolveBaseUrl())->toBe(ACCOUNT_URL . '/api/v2');

    Snowflake::make('http://evil.example', new OAuthAccessToken(EXECUTE_TOKEN));
})->throws(InvalidArgumentException::class);

it('refuses a user agent suffix that could split a header', function (): void {
    new Options(userAgentSuffix: "x\r\nX-Evil: 1");
})->throws(InvalidArgumentException::class);

it('refuses out of range options', function (int $buffer, int $connect): void {
    new Options(httpTimeoutBufferSeconds: $buffer, connectTimeoutSeconds: $connect);
})->throws(InvalidArgumentException::class)->with([
    'negative buffer' => [-1, 10],
    'zero connect timeout' => [5, 0],
]);

it('turns redirects off so a statement can never be resent', function (): void {
    [$client, $mock] = snowflakeWith([MockResponse::make('{}', 307, ['Location' => 'https://evil.example/x'])]);

    expect(fn () => $client->execute(Statement::make('INSERT INTO t VALUES (1)')))
        ->toThrow(fn (TransportException $e) => expect($e->httpStatus)->toBe(307));

    $config = lastPendingRequest($mock)->config()->all();

    expect($config['allow_redirects'])->toBeFalse()
        ->and($config['verify'])->toBeTrue();
    $mock->assertSentCount(1);
});

it('always sends through the Guzzle sender regardless of the Saloon default', function (): void {
    [$client] = snowflakeWith([MockResponse::make(jsonFixture('statement_200_single_partition'))]);

    expect($client->connector()->sender())->toBeInstanceOf(GuzzleSender::class);
});
