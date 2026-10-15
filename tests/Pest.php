<?php

declare(strict_types=1);

use InterWorks\SnowflakeApi\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');

/**
 * The raw contents of a JSON fixture under tests/Fixtures.
 */
function jsonFixture(string $name): string
{
    $contents = file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json');

    if ($contents === false) {
        throw new RuntimeException('Missing fixture ' . $name);
    }

    return $contents;
}
