<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\ClientConfigRouteTestCase;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The client-config route with `enabled = true`, the default URI and
 * `web`-only middleware (so guests reach the controller and the JSON
 * 401 behaviour can be asserted rather than a `web` auth redirect).
 */
final class ClientConfigRouteTest extends ClientConfigRouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('appsync-events.http_endpoint', 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event');
        config()->set('appsync-events.realtime_endpoint', 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime');
        config()->set('appsync-events.signing_secret', 'route-secret');
    }

    #[Test]
    public function it_registers_the_named_route_on_the_default_uri(): void
    {
        $this->assertTrue(Route::has('appsync-events.client-config'));
    }

    #[Test]
    public function it_returns_the_client_configuration_for_an_authenticated_user(): void
    {
        $this->actingAs(new TestUser(11));

        $response = $this->getJson('/broadcasting/appsync');

        $response->assertOk()->assertJsonStructure([
            'wsEndpoint',
            'httpHost',
            'connectToken',
            'authEndpoint',
            'defaultNamespace',
            'inactivityTimeout',
        ]);

        $claims = (new TokenSigner('route-secret'))->verify($response->json('connectToken'));

        $this->assertSame('connect', $claims['purpose']);
        $this->assertSame('11', $claims['uid']);
    }

    #[Test]
    public function it_returns_a_json_401_for_guests_without_redirecting(): void
    {
        $response = $this->get('/broadcasting/appsync');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
