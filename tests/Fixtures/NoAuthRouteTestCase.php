<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Boots the application with `appsync-events.routes.auth.enabled` off.
 */
abstract class NoAuthRouteTestCase extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('appsync-events.routes.auth.enabled', false);
    }
}
