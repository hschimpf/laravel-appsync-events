<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Boots the application with the client-config route enabled on its
 * default URI and `web` middleware only — guests reach the controller
 * so the JSON 401 behaviour can be asserted.
 */
abstract class ClientConfigRouteTestCase extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('appsync-events.routes.client_config.enabled', true);
        $app['config']->set('appsync-events.routes.client_config.middleware', ['web']);
    }
}
