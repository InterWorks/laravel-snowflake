<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Tests;

use Illuminate\Foundation\Application;
use InterWorks\SnowflakeApi\SnowflakeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base class for package tests. It boots a Testbench application with the package service provider registered.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SnowflakeServiceProvider::class];
    }
}
