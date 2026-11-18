<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests;

use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Get the package providers to register for the test application.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AppSyncEventsServiceProvider::class,
        ];
    }
}
