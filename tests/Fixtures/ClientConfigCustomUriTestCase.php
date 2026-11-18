<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Boots the application with the client-config route enabled on a
 * custom URI.
 */
abstract class ClientConfigCustomUriTestCase extends TestCase
{
    /**
     * The custom route URI used by this case.
     */
    public const URI = 'custom/appsync-config';

    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('appsync-events.routes.client_config.enabled', true);
        $app['config']->set('appsync-events.routes.client_config.uri', self::URI);
        $app['config']->set('appsync-events.routes.client_config.middleware', ['web']);
    }
}
