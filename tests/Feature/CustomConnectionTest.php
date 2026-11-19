<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Auth\InvalidTokenException;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\CustomConnectionProvider;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\CustomConnectionTestCase;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\Test;

/**
 * A PHPUnit test because the custom connection must be registered by a
 * provider that runs before the package provider — a per-test
 * {@see CustomConnectionTestCase} is required.
 */
final class CustomConnectionTest extends CustomConnectionTestCase
{
    #[Test]
    public function it_never_overwrites_a_user_defined_appsync_connection(): void
    {
        $this->assertSame(
            CustomConnectionProvider::CONNECTION,
            config('broadcasting.connections.appsync'),
        );
    }

    #[Test]
    public function it_does_not_carry_package_previous_secrets_onto_a_connection_secret(): void
    {
        config()->set('appsync-events.signing_secret', 'package-secret');
        config()->set('appsync-events.previous_signing_secrets', 'package-old-secret');

        $signer = $this->connectionSigner();

        $this->assertSame(
            'u-1',
            $signer->verify((new TokenSigner('connection-secret'))->connectToken('u-1'))['uid'],
        );

        $this->expectException(InvalidTokenException::class);
        $signer->verify((new TokenSigner('package-old-secret'))->connectToken('u-1'));
    }

    #[Test]
    public function it_honours_a_connections_own_previous_secrets(): void
    {
        config()->set('appsync-events.previous_signing_secrets', 'package-old-secret');
        config()->set('broadcasting.connections.appsync', CustomConnectionProvider::CONNECTION + [
            'previous_signing_secrets' => 'connection-old-secret',
        ]);

        $this->assertSame(
            'u-1',
            $this->connectionSigner()->verify((new TokenSigner('connection-old-secret'))->connectToken('u-1'))['uid'],
        );
    }

    /**
     * The signer held by the resolved `appsync` broadcaster.
     */
    private function connectionSigner(): TokenSigner
    {
        $signer = (fn (): TokenSigner => $this->signer)->call(Broadcast::connection('appsync'));

        assert($signer instanceof TokenSigner);

        return $signer;
    }
}
