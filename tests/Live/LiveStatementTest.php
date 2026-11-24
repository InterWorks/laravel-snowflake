<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Auth\ProgrammaticAccessToken;
use InterWorks\SnowflakeApi\Snowflake;
use InterWorks\SnowflakeApi\Statement\Statement;

/**
 * Runs against a real account. Export SNOWFLAKE_LIVE=1, SNOWFLAKE_ACCOUNT_URL and SNOWFLAKE_PAT in the shell, then run
 * `composer test:live`. The values are never written to a file.
 */
function liveEnv(string $name): string
{
    $value = getenv($name);

    return $value === false ? '' : $value;
}

it('runs a statement against a real Snowflake account', function (): void {
    $token = new ProgrammaticAccessToken(liveEnv('SNOWFLAKE_PAT'));
    $snowflake = Snowflake::make(liveEnv('SNOWFLAKE_ACCOUNT_URL'), $token);

    $result = $snowflake->execute(Statement::make('SELECT CURRENT_USER() AS U, 1 AS ONE'));

    expect($result->rows)->toHaveCount(1)
        ->and($result->columns)->toHaveCount(2)
        ->and($result->columns[0]->name)->toBe('U')
        ->and($result->columns[1]->name)->toBe('ONE')
        ->and($result->rows[0][1])->toBe('1')
        ->and($result->numRows)->toBe(1)
        ->and($result->hasMorePartitions())->toBeFalse();
})
    ->group('live')
    ->skip(
        fn (): bool => liveEnv('SNOWFLAKE_LIVE') !== '1'
            || liveEnv('SNOWFLAKE_ACCOUNT_URL') === ''
            || liveEnv('SNOWFLAKE_PAT') === '',
        'Set SNOWFLAKE_LIVE=1, SNOWFLAKE_ACCOUNT_URL and SNOWFLAKE_PAT to run the live test.'
    );

it('mints a Streamlit embed url against a real Snowflake account', function (): void {
    [$database, $schema, $name] = array_pad(explode('.', liveEnv('SNOWFLAKE_LIVE_STREAMLIT')), 3, '');
    $token = new ProgrammaticAccessToken(liveEnv('SNOWFLAKE_PAT'));
    $snowflake = Snowflake::make(liveEnv('SNOWFLAKE_ACCOUNT_URL'), $token);

    $embedUrl = $snowflake->generateStreamlitEmbedUrl(
        $database,
        $schema,
        $name,
        liveEnv('SNOWFLAKE_LIVE_ROLE'),
        liveEnv('SNOWFLAKE_LIVE_ORIGIN')
    );

    expect($embedUrl->url())->toStartWith('https://');
})
    ->group('live')
    ->skip(
        fn (): bool => liveEnv('SNOWFLAKE_LIVE') !== '1'
            || liveEnv('SNOWFLAKE_ACCOUNT_URL') === ''
            || liveEnv('SNOWFLAKE_PAT') === ''
            || liveEnv('SNOWFLAKE_LIVE_STREAMLIT') === ''
            || liveEnv('SNOWFLAKE_LIVE_ROLE') === ''
            || liveEnv('SNOWFLAKE_LIVE_ORIGIN') === '',
        'Also set SNOWFLAKE_LIVE_STREAMLIT (DATABASE.SCHEMA.APP), SNOWFLAKE_LIVE_ROLE and SNOWFLAKE_LIVE_ORIGIN to run '
        . 'the live embed test. The PAT user needs USAGE and EMBED on the app, and the origin must be registered.'
    );
