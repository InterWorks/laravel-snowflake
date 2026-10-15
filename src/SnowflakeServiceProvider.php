<?php

declare(strict_types=1);

namespace InterWorks\SnowflakeApi;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers the config file. Nothing is bound in the container: consumers construct Snowflake per account and per
 * credential.
 */
final class SnowflakeServiceProvider extends PackageServiceProvider
{
    /**
     * Names the package and publishes its config file.
     */
    public function configurePackage(Package $package): void
    {
        $package->name('snowflake')->hasConfigFile();
    }
}
