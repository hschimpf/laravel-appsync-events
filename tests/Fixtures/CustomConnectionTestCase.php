<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsServiceProvider;
use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Test case registering {@see CustomConnectionProvider} ahead of the
 * package provider so a user-defined `appsync` connection already exists
 * when the package registers.
 */
abstract class CustomConnectionTestCase extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function getPackageProviders($app): array
    {
        return [
            CustomConnectionProvider::class,
            AppSyncEventsServiceProvider::class,
        ];
    }
}
