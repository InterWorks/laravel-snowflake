<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base class for package tests. The service provider is registered in step 7 of the build order.
 */
abstract class TestCase extends Orchestra
{
}
