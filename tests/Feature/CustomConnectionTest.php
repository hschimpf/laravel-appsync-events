<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Feature;

use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\CustomConnectionProvider;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\CustomConnectionTestCase;
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
}
