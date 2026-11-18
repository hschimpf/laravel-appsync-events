<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\CachedRoutesTestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * With routes "cached" (the `routes.cached` binding), neither the
 * package nor `BroadcastManager::routes()` may add a broadcasting auth
 * route — the compiled route table is the only source of truth.
 */
final class CachedRoutesTest extends CachedRoutesTestCase
{
    #[Test]
    public function no_auth_route_is_registered_when_routes_are_cached(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->uri() === 'broadcasting/auth');

        $this->assertCount(0, $routes);
    }
}
