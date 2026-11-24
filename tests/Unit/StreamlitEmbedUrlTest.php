<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Auth\Authenticator;
use InterWorks\SnowflakeApi\Auth\OAuthAccessToken;
use InterWorks\SnowflakeApi\Auth\ProgrammaticAccessToken;
use InterWorks\SnowflakeApi\Exceptions\AuthenticationException;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Exceptions\RateLimitedException;
use InterWorks\SnowflakeApi\Exceptions\SnowflakeException;
use InterWorks\SnowflakeApi\Exceptions\TransportException;
use InterWorks\SnowflakeApi\Http\Requests\GenerateStreamlitEmbedUrlRequest;
use InterWorks\SnowflakeApi\Options;
use InterWorks\SnowflakeApi\Result\EmbedUrl;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\Support\AccountUrl;
use InterWorks\SnowflakeApi\Support\ParentOrigin;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

const EMBED_TOKEN = 'embed-token-4f1a9c.payload.signature';
const EMBED_ACCOUNT_URL = 'https://myorg-myaccount.snowflakecomputing.com';
const EMBED_URL = 'https://abc123.snowflake.app/embed?code=single-use-code-9e8d7c';
const EMBED_ORIGIN = 'https://portal.example.com';

/**
 * A client whose connector answers with the given mock responses.
 *
 * @param array<MockResponse|callable> $responses
 * @return array{0: Snowflake, 1: MockClient}
 */
function embedClientWith(array $responses, ?Authenticator $authenticator = null, ?Options $options = null): array
{
    $client = new Snowflake(
        AccountUrl::fromString(EMBED_ACCOUNT_URL),
        $authenticator ?? new OAuthAccessToken(EMBED_TOKEN),
        $options ?? new Options(),
    );
    $mock = new MockClient($responses);
    $client->connector()->withMockClient($mock);

    return [$client, $mock];
}

function embedUrlBody(mixed $url = EMBED_URL): string
{
    return json_encode(['embed_url' => $url], JSON_THROW_ON_ERROR);
}

function mintEmbedUrl(Snowflake $client, string $origin = EMBED_ORIGIN): EmbedUrl
{
    return $client->generateStreamlitEmbedUrl('ANALYTICS', 'APPS', 'SALES_APP', 'VIEWER_ROLE', $origin);
}

// ---------------------------------------------------------------------------------------------------------------------
// Request shape
// ---------------------------------------------------------------------------------------------------------------------

it('sends the documented embed url request', function (Closure $makeAuthenticator, string $tokenType): void {
    [$client, $mock] = embedClientWith([MockResponse::make(embedUrlBody())], $makeAuthenticator());

    mintEmbedUrl($client, 'HTTPS://Portal.Example.com/');

    $mock->assertSent(GenerateStreamlitEmbedUrlRequest::class);
    $mock->assertSentCount(1);
    $pending = $mock->getLastPendingRequest();

    expect($pending)->not->toBeNull();

    if ($pending === null) {
        return;
    }

    $headers = $pending->headers()->all();

    expect($pending->getMethod()->value)->toBe('POST')
        ->and($pending->getUrl())->toBe(
            EMBED_ACCOUNT_URL . '/api/v2/databases/ANALYTICS/schemas/APPS/streamlits/SALES_APP:generate-embed-url'
        )
        ->and($pending->body()?->all())->toBe(['parent_origin' => EMBED_ORIGIN])
        ->and($headers['Authorization'])->toBe('Bearer ' . EMBED_TOKEN)
        ->and($headers['X-Snowflake-Authorization-Token-Type'])->toBe($tokenType)
        ->and($headers['X-Snowflake-Role'])->toBe('VIEWER_ROLE')
        ->and($headers['Content-Type'])->toBe('application/json')
        ->and($headers['Accept'])->toBe('application/json')
        ->and($pending->config()->all())->toMatchArray([
            'timeout' => GenerateStreamlitEmbedUrlRequest::TIMEOUT_SECONDS,
            'allow_redirects' => false,
            'verify' => true,
        ]);
})->with([
    'oauth' => [fn () => new OAuthAccessToken(EMBED_TOKEN), 'OAUTH'],
    'pat' => [fn () => new ProgrammaticAccessToken(EMBED_TOKEN), 'PROGRAMMATIC_ACCESS_TOKEN'],
]);

it('accepts an already validated ParentOrigin', function (): void {
    [$client, $mock] = embedClientWith([MockResponse::make(embedUrlBody())]);

    $client->generateStreamlitEmbedUrl('DB', 'SCH', 'APP', 'ROLE', ParentOrigin::fromString('http://localhost:3000'));

    expect($mock->getLastPendingRequest()?->body()?->all())->toBe(['parent_origin' => 'http://localhost:3000']);
});

it('refuses an identifier or origin before sending anything', function (array $arguments): void {
    [$client, $mock] = embedClientWith([MockResponse::make(embedUrlBody())]);

    try {
        $client->generateStreamlitEmbedUrl(...$arguments);
    } catch (InvalidArgumentException) {
        $mock->assertNothingSent();

        return;
    }

    $this->fail('Expected an InvalidArgumentException.');
})->with([
    'quoted database' => [['"db"', 'SCH', 'APP', 'ROLE', EMBED_ORIGIN]],
    'schema with a slash' => [['DB', 'S/../X', 'APP', 'ROLE', EMBED_ORIGIN]],
    'name with a colon' => [['DB', 'SCH', 'APP:delete', 'ROLE', EMBED_ORIGIN]],
    'empty role' => [['DB', 'SCH', 'APP', '', EMBED_ORIGIN]],
    'role with a newline' => [['DB', 'SCH', 'APP', "ROLE\r\nX-Evil: 1", EMBED_ORIGIN]],
    'origin with a path' => [['DB', 'SCH', 'APP', 'ROLE', 'https://portal.example.com/page']],
    'plain http origin' => [['DB', 'SCH', 'APP', 'ROLE', 'http://portal.example.com']],
]);

// ---------------------------------------------------------------------------------------------------------------------
// The answer
// ---------------------------------------------------------------------------------------------------------------------

it('returns the embed url from a 200', function (): void {
    [$client] = embedClientWith([MockResponse::make(embedUrlBody())]);

    expect(mintEmbedUrl($client)->url())->toBe(EMBED_URL);
});

it('rejects a 200 without an https embed url', function (string $body): void {
    [$client] = embedClientWith([MockResponse::make($body)]);

    try {
        mintEmbedUrl($client);
    } catch (TransportException $e) {
        foreach ([$e->getMessage(), (string) $e, $e->getTraceAsString()] as $text) {
            expect($text)->not->toContain('javascript')
                ->and($text)->not->toContain('single-use-code');
        }

        expect($e->httpStatus)->toBe(200);

        return;
    }

    $this->fail('Expected a TransportException.');
})->with([
    'empty object' => ['{}'],
    'not json' => ['<html></html>'],
    'null url' => [embedUrlBody(null)],
    'number' => [embedUrlBody(42)],
    'empty string' => [embedUrlBody('')],
    'http' => [embedUrlBody('http://abc123.snowflake.app/embed?code=single-use-code')],
    'javascript' => [embedUrlBody('javascript:alert(document.cookie)')],
    'relative' => [embedUrlBody('/embed?code=single-use-code')],
    'userinfo' => [embedUrlBody('https://evil@abc123.snowflake.app/?code=single-use-code')],
    'whitespace' => [embedUrlBody('https://abc123.snowflake.app/embed?code=single-use-code x')],
    'control character in the path' => [embedUrlBody("https://abc123.snowflake.app/embed\x01?code=single-use-code")],
    'delete character in the query' => [embedUrlBody("https://abc123.snowflake.app/embed?code=single-use-code\x7f")],
    'no host' => [embedUrlBody('https://:443/embed?code=single-use-code')],
    'empty host' => [embedUrlBody('https:///embed?code=single-use-code')],
]);

it('maps 401 and 403 to AuthenticationException', function (int $status): void {
    [$client] = embedClientWith([
        MockResponse::make(['code' => '390144', 'message' => 'Invalid OAuth access token.'], $status),
    ]);

    try {
        mintEmbedUrl($client);
    } catch (AuthenticationException $e) {
        expect($e->httpStatus)->toBe($status)
            ->and($e->snowflakeCode)->toBe('390144')
            ->and($e->getMessage())->toBe('Invalid OAuth access token.');

        return;
    }

    $this->fail('Expected an AuthenticationException.');
})->with([401, 403]);

it('maps 429 to RateLimitedException and reads Retry-After', function (): void {
    [$client] = embedClientWith([MockResponse::make(['message' => 'Too many requests.'], 429, ['Retry-After' => '7'])]);

    try {
        mintEmbedUrl($client);
    } catch (RateLimitedException $e) {
        expect($e->httpStatus)->toBe(429)
            ->and($e->retryAfterSeconds)->toBe(7);

        return;
    }

    $this->fail('Expected a RateLimitedException.');
});

it('maps every other status, including 202 and 422, to TransportException', function (int $status): void {
    [$client] = embedClientWith([
        MockResponse::make(['code' => '002003', 'message' => 'Streamlit SALES_APP does not exist.'], $status),
    ]);

    try {
        mintEmbedUrl($client);
    } catch (TransportException $e) {
        expect($e->httpStatus)->toBe($status)
            ->and($e->snowflakeCode)->toBe('002003')
            ->and($e->getMessage())->toBe('Streamlit SALES_APP does not exist.');

        return;
    }

    $this->fail('Expected a TransportException.');
})->with([202, 400, 404, 409, 422, 500, 503]);

it('reports an unreadable body on an error status', function (): void {
    [$client] = embedClientWith([MockResponse::make('<html>Bad gateway</html>', 502)]);

    expect(fn () => mintEmbedUrl($client))->toThrow(
        TransportException::class,
        'Snowflake returned status 502 with an unreadable body.'
    );
});

it('refuses a response above the size limit', function (): void {
    [$client] = embedClientWith(
        [MockResponse::make(embedUrlBody(EMBED_URL . str_repeat('x', 100)))],
        options: new Options(maxResponseBytes: 64),
    );

    expect(fn () => mintEmbedUrl($client))->toThrow(TransportException::class, 'exceeded the limit of 64 bytes');
});

it('never retries an embed url request', function (int $status): void {
    [$client, $mock] = embedClientWith([MockResponse::make('{}', $status), MockResponse::make(embedUrlBody())]);

    try {
        mintEmbedUrl($client);
    } catch (SnowflakeException) {
        // expected
    }

    $mock->assertSentCount(1);
})->with([500, 502, 503, 429, 408]);

it('never leaks the token or a previous exception from any thrown exception', function (Closure $makeResponse): void {
    [$client] = embedClientWith([$makeResponse()]);

    try {
        mintEmbedUrl($client);
    } catch (SnowflakeException $e) {
        foreach ([$e->getMessage(), (string) $e, $e->getTraceAsString()] as $text) {
            expect($text)->not->toContain(EMBED_TOKEN)
                ->and($text)->not->toContain('Bearer embed-token');
        }

        expect($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected a SnowflakeException.');
})->with([
    '401' => [fn () => MockResponse::make(['message' => 'Invalid OAuth access token.'], 401)],
    '404' => [fn () => MockResponse::make(['message' => 'Not found.'], 404)],
    '429' => [fn () => MockResponse::make(['message' => 'Slow down.'], 429)],
    'unexpected 200' => [fn () => MockResponse::make('{}', 200)],
    'fatal' => [
        fn () => MockResponse::make()->throw(
            fn (PendingRequest $pending) => new FatalRequestException(
                new RuntimeException('Authorization: Bearer ' . EMBED_TOKEN),
                $pending
            )
        ),
    ],
]);

// ---------------------------------------------------------------------------------------------------------------------
// The embed url is a credential
// ---------------------------------------------------------------------------------------------------------------------

it('does not reveal the embed url through any dump or cast', function (): void {
    $embedUrl = new EmbedUrl(EMBED_URL);

    ob_start();
    var_dump($embedUrl);
    $varDump = (string) ob_get_clean();

    $dumper = new CliDumper();
    $dumper->setColors(false);
    $varDumper = (string) $dumper->dump((new VarCloner())->cloneVar($embedUrl), true);

    $outputs = [
        'print_r' => print_r($embedUrl, true),
        'var_dump' => $varDump,
        'var_export' => var_export($embedUrl, true),
        'json_encode' => json_encode($embedUrl, JSON_THROW_ON_ERROR),
        'array cast' => print_r((array) $embedUrl, true),
        'VarDumper' => $varDumper,
    ];

    foreach ($outputs as $output) {
        expect($output)->not->toContain('single-use-code')
            ->and($output)->not->toContain('snowflake.app');
    }

    expect($outputs['var_dump'])->toContain('***');
});

it('refuses to serialise an embed url', function (): void {
    serialize(new EmbedUrl(EMBED_URL));
})->throws(Exception::class);

it('does not keep the embed url in plain form inside its closure', function (): void {
    $embedUrl = new EmbedUrl(EMBED_URL);

    $closure = (new ReflectionProperty($embedUrl, 'reveal'))->getValue($embedUrl);
    $static = (new ReflectionFunction($closure))->getStaticVariables();

    expect($static)->not->toContain(EMBED_URL)
        ->and(implode('', array_map('bin2hex', $static)))->not->toContain(bin2hex('single-use-code'))
        ->and($embedUrl->url())->toBe(EMBED_URL);
});
