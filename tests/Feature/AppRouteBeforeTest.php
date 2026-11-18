<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\AppRouteBeforeTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * Route registration orders covered here:
 *  - `before` (this case): the app registers `Broadcast::routes()` from
 *    another provider's `boot()` — it runs before the package's deferred
 *    `app->booted()` registration, so the package must skip its own
 *    registration entirely. See {@see AppRouteAfterTest} for the
 *    "app registers last" ordering, which Testbench can simulate via a
 *    second `booted` callback. A route registered inside `routes/*.php`
 *    files loaded by the app's RouteServiceProvider always falls into
 *    the "before" bucket (providers boot before booted callbacks).
 */
final class AppRouteBeforeTest extends AppRouteBeforeTestCase
{
    #[Test]
    public function the_app_defined_auth_route_keeps_its_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->uri() === 'broadcasting/auth')
            ->values();

        $this->assertCount(1, $routes, 'Expected exactly one broadcasting/auth route');

        $route = $routes->first();

        $this->assertContains('POST', $route->methods());
        $this->assertContains('app-auth', $route->gatherMiddleware());
    }
}
