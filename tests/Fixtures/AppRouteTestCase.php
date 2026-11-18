<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\AppSyncEventsServiceProvider;
use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Base test case registering {@see BroadcastRouteRegistrarProvider}
 * after the package provider, simulating an application that defines
 * its own broadcasting auth route.
 */
abstract class AppRouteTestCase extends TestCase
{
    /**
     * When the simulated app registers its route: `boot` or `booted`.
     */
    protected string $broadcastRouteMode = 'boot';

    /**
     * {@inheritDoc}
     */
    protected function getPackageProviders($app): array
    {
        return [
            AppSyncEventsServiceProvider::class,
            BroadcastRouteRegistrarProvider::class,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        BroadcastRouteRegistrarProvider::$mode = $this->broadcastRouteMode;
    }
}
