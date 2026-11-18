<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;
use Illuminate\Foundation\Application;

/**
 * Simulates `route:cache`: binding `routes.cached` makes
 * {@see Application::routesAreCached()} return
 * true before providers boot, so neither the package nor
 * `BroadcastManager::routes()` may register broadcasting routes.
 */
abstract class CachedRoutesTestCase extends TestCase
{
    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app->instance('routes.cached', true);
    }
}
