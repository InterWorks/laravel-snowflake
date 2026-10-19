<?php

declare(strict_types=1);

const PACKAGE_NAMESPACE = 'InterWorks\SnowflakeApi';
const TEST_NAMESPACE = 'InterWorks\SnowflakeApi\Tests';

arch('the package never uses debugging or output functions')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'var_export', 'error_log'])
    ->not->toBeUsed()
    ->ignoring(TEST_NAMESPACE);

arch('the package never calls the HTTP stack directly')
    ->expect(['curl_init', 'curl_exec', 'file_get_contents', 'fopen', 'fsockopen', 'stream_socket_client'])
    ->not->toBeUsed()
    ->ignoring(TEST_NAMESPACE);

arch('Guzzle is only reachable through Saloon')
    ->expect('GuzzleHttp')
    ->not->toBeUsedIn(PACKAGE_NAMESPACE)
    ->ignoring(TEST_NAMESPACE);

foreach (['Psr\Log', 'Monolog'] as $loggingNamespace) {
    arch("the package never logs through {$loggingNamespace}")
        ->expect($loggingNamespace)
        ->not->toBeUsedIn(PACKAGE_NAMESPACE)
        ->ignoring(TEST_NAMESPACE);
}

arch('the package avoids insecure or unsafe functions')
    ->expect(['eval', 'exec', 'shell_exec', 'system', 'passthru', 'unserialize', 'md5', 'sha1', 'rand', 'mt_rand'])
    ->not->toBeUsed()
    ->ignoring(TEST_NAMESPACE);

arch('every file declares strict types')
    ->expect(PACKAGE_NAMESPACE)
    ->toUseStrictTypes()
    ->ignoring(TEST_NAMESPACE);

arch('every class is final except the exception base')
    ->expect(PACKAGE_NAMESPACE)
    ->classes()
    ->toBeFinal()
    ->ignoring([TEST_NAMESPACE, 'InterWorks\SnowflakeApi\Exceptions\SnowflakeException']);

arch('value objects are readonly')
    ->expect([
        'InterWorks\SnowflakeApi\Options',
        'InterWorks\SnowflakeApi\Result',
        'InterWorks\SnowflakeApi\Statement\Binding',
        'InterWorks\SnowflakeApi\Support\AccountUrl',
        'InterWorks\SnowflakeApi\Auth\OAuthAccessToken',
        'InterWorks\SnowflakeApi\Auth\ProgrammaticAccessToken',
    ])
    ->toBeReadonly();

arch('exceptions extend the package base')
    ->expect('InterWorks\SnowflakeApi\Exceptions')
    ->toExtend('InterWorks\SnowflakeApi\Exceptions\SnowflakeException')
    ->ignoring('InterWorks\SnowflakeApi\Exceptions\InvalidArgumentException');

arch('only the service provider touches the Laravel container')
    ->expect('Illuminate')
    ->not->toBeUsedIn(PACKAGE_NAMESPACE)
    ->ignoring([TEST_NAMESPACE, 'InterWorks\SnowflakeApi\SnowflakeServiceProvider']);
