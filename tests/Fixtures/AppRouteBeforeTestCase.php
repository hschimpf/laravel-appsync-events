<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

/**
 * Simulates an application whose broadcasting auth route is registered
 * (in another provider's `boot()`) before the package's deferred route
 * registration runs.
 */
abstract class AppRouteBeforeTestCase extends AppRouteTestCase
{
    /**
     * {@inheritDoc}
     */
    protected string $broadcastRouteMode = 'boot';
}
