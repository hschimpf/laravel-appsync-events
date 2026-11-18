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

    /**
     * Define the environment for the test application.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app)
    {
        // Required by the `web` middleware group (encrypted cookies).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
