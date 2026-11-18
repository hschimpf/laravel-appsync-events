<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\NoAuthRouteTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * `appsync-events.routes.auth.enabled = false` must leave no
 * broadcasting auth route behind.
 */
final class DisabledAuthRouteTest extends NoAuthRouteTestCase
{
    #[Test]
    public function no_auth_route_is_registered_when_disabled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->uri() === 'broadcasting/auth');

        $this->assertCount(0, $routes);
    }

    #[Test]
    public function posting_to_the_auth_endpoint_returns_404(): void
    {
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-orders.5'])
            ->assertNotFound();
    }
}
