<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\ClientConfigCustomUriTestCase;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\Test;

/**
 * The client-config route honours a custom configured URI.
 */
final class ClientConfigCustomUriRouteTest extends ClientConfigCustomUriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('appsync-events.http_endpoint', 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event');
        config()->set('appsync-events.realtime_endpoint', 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime');
        config()->set('appsync-events.signing_secret', 'route-secret');
    }

    #[Test]
    public function it_serves_the_config_on_the_custom_uri(): void
    {
        $this->actingAs(new TestUser(11));

        $this->getJson('/'.self::URI)->assertOk()->assertJsonStructure(['connectToken']);
    }

    #[Test]
    public function the_default_uri_is_not_registered(): void
    {
        $this->getJson('/broadcasting/appsync')->assertNotFound();
    }
}
