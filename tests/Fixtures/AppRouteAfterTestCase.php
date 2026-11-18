<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

/**
 * Simulates an application whose broadcasting auth route is registered
 * inside an `app->booted()` callback queued after the package's own —
 * the latest possible registration order Laravel allows.
 */
abstract class AppRouteAfterTestCase extends AppRouteTestCase
{
    /**
     * {@inheritDoc}
     */
    protected string $broadcastRouteMode = 'booted';
}
