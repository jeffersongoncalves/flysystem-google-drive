<?php

declare(strict_types=1);

namespace JeffersonGoncalves\FlysystemGoogleDrive\Tests;

use JeffersonGoncalves\FlysystemGoogleDrive\FlysystemGoogleDriveServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FlysystemGoogleDriveServiceProvider::class,
        ];
    }
}
