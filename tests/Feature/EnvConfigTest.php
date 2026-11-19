<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Facades\AppSyncEvents;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\EnvConfigTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A PHPUnit test because the APPSYNC_* variables must be injected before
 * the framework loads configuration — something Pest's per-file uses()
 * cannot vary (beforeEach hooks run after the application is created).
 */
final class EnvConfigTest extends EnvConfigTestCase
{
    #[Test]
    public function it_feeds_appsync_environment_values_into_the_package_config(): void
    {
        $this->assertSame('https://envid.appsync-api.eu-west-1.amazonaws.com/event', config('appsync-events.http_endpoint'));
        $this->assertSame('wss://envid.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime', config('appsync-events.realtime_endpoint'));
        $this->assertSame('env-secret', config('appsync-events.signing_secret'));
        $this->assertSame('env-old-secret, env-older-secret', config('appsync-events.previous_signing_secrets'));
        $this->assertSame('env-pub', config('appsync-events.namespaces.public'));
        $this->assertSame('env-priv', config('appsync-events.namespaces.private'));
    }

    #[Test]
    public function it_verifies_tokens_signed_with_a_previous_secret_from_the_environment(): void
    {
        $token = (new TokenSigner('env-older-secret'))->connectToken('u-9');

        $this->assertSame('u-9', AppSyncEvents::signer()->verify($token)['uid']);
    }
}
