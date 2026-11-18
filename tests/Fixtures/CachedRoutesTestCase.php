<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;
use Illuminate\Foundation\Application;

/**
 * Simulates `route:cache`. Newer Laravel releases check the
 * `routes.cached` binding, while older ones check that the compiled
 * routes file exists — both are set here so
 * {@see Application::routesAreCached()} returns true before providers
 * boot on every supported version, and neither the package nor
 * `BroadcastManager::routes()` may register broadcasting routes.
 */
abstract class CachedRoutesTestCase extends TestCase
{
    /**
     * Whether this test created the compiled routes file itself.
     */
    private bool $createdCachedRoutesFile = false;

    /**
     * {@inheritDoc}
     */
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app->instance('routes.cached', true);

        $path = $app->getCachedRoutesPath();

        if (! file_exists($path)) {
            file_put_contents($path, '<?php return [];');
            $this->createdCachedRoutesFile = true;
        }
    }

    /**
     * Remove the simulated compiled routes file so it cannot mark routes
     * as cached for later tests on file-checking Laravel versions.
     */
    protected function tearDown(): void
    {
        if ($this->createdCachedRoutesFile) {
            @unlink(app()->getCachedRoutesPath());
            $this->createdCachedRoutesFile = false;
        }

        parent::tearDown();
    }
}
