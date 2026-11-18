<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\AppRouteAfterTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The "app registers last" ordering: the app queues a second
 * `app->booted()` callback that calls `Broadcast::routes()` after the
 * package registered its own. Laravel's route collection keys compiled
 * routes by method+domain+URI, so the later registration replaces the
 * package's — the app definition still wins and only one route remains.
 */
final class AppRouteAfterTest extends AppRouteAfterTestCase
{
    #[Test]
    public function the_app_defined_auth_route_wins_even_when_registered_last(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->uri() === 'broadcasting/auth')
            ->values();

        $this->assertCount(1, $routes, 'Expected exactly one broadcasting/auth route');

        $route = $routes->first();

        $this->assertContains('app-auth', $route->gatherMiddleware());
    }
}
