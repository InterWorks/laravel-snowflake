<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Auth\OAuthAccessToken;
use InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException;
use InterWorks\SnowflakeApi\Options;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\SnowflakeServiceProvider;
use InterWorks\SnowflakeApi\Statement\Statement;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('registers the provider and merges the default config', function (): void {
    expect(app()->getProviders(SnowflakeServiceProvider::class))->not->toBeEmpty()
        ->and(config('snowflake.http_timeout_buffer'))->toBe(5)
        ->and(config('snowflake.connect_timeout'))->toBe(10)
        ->and(config('snowflake.user_agent_suffix'))->toBeNull();
});

it('binds nothing in the container', function (): void {
    expect(app()->bound(Snowflake::class))->toBeFalse()
        ->and(app()->bound(Options::class))->toBeFalse();
});

it('reads the defaults through Options::fromLaravelConfig', function (): void {
    $options = Options::fromLaravelConfig();

    expect($options->httpTimeoutBufferSeconds)->toBe(5)
        ->and($options->connectTimeoutSeconds)->toBe(10)
        ->and($options->userAgentSuffix)->toBeNull();
});

it('reflects overridden config values in Options::fromLaravelConfig', function (): void {
    config()->set('snowflake.http_timeout_buffer', 9);
    config()->set('snowflake.connect_timeout', 4);
    config()->set('snowflake.user_agent_suffix', 'curator/2.0');

    $options = Options::fromLaravelConfig();

    expect($options->httpTimeoutBufferSeconds)->toBe(9)
        ->and($options->connectTimeoutSeconds)->toBe(4)
        ->and($options->userAgentSuffix)->toBe('curator/2.0');
});

it('treats an empty user agent suffix in config as unset', function (): void {
    config()->set('snowflake.user_agent_suffix', '');

    expect(Options::fromLaravelConfig()->userAgentSuffix)->toBeNull();
});

it('rejects an invalid configured value', function (): void {
    config()->set('snowflake.connect_timeout', 0);

    Options::fromLaravelConfig();
})->throws(InvalidArgumentException::class);

it('applies the config to a client built with Snowflake::make', function (): void {
    config()->set('snowflake.http_timeout_buffer', 8);
    config()->set('snowflake.user_agent_suffix', 'curator/2.0');

    $client = Snowflake::make('https://myorg-myaccount.snowflakecomputing.com', new OAuthAccessToken('t0ken'));
    $mock = new MockClient([MockResponse::make(jsonFixture('statement_200_single_partition'))]);
    $client->connector()->withMockClient($mock);

    $client->execute(Statement::make('SELECT 1')->timeout(10));

    $pending = $mock->getLastPendingRequest();

    expect($pending?->config()->get('timeout'))->toBe(18)
        ->and($pending?->headers()->get('User-Agent'))
        ->toBe('interworks-laravel-snowflake/' . Snowflake::VERSION . ' curator/2.0');
});

it('publishes the config file under the package name', function (): void {
    expect(file_exists(dirname(__DIR__, 2) . '/config/snowflake.php'))->toBeTrue();
});
