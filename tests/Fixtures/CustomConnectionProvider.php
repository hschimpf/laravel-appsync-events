<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * Simulates an application that defines its own `appsync` broadcasting
 * connection before this package's service provider runs.
 */
final class CustomConnectionProvider extends ServiceProvider
{
    /**
     * The connection definition the "application" provides.
     *
     * @var array<string, mixed>
     */
    public const CONNECTION = [
        'driver' => 'appsync',
        'http_endpoint' => 'https://customid.appsync-api.ap-southeast-2.amazonaws.com/event',
        'signing_secret' => 'connection-secret',
        'default_namespace' => 'tenant',
    ];

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->app['config']->set('broadcasting.connections.appsync', self::CONNECTION);
    }
}
