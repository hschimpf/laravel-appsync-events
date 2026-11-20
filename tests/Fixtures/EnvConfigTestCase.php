<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures;

use HDSSolutions\Laravel\AppSyncEvents\Tests\TestCase;

/**
 * Test case that injects APPSYNC_* variables into $_SERVER before the
 * framework loads configuration, so the config file's env() reads can be
 * verified end to end.
 */
abstract class EnvConfigTestCase extends TestCase
{
    /**
     * The env vars injected for every test using this case.
     *
     * @var array<string, string>
     */
    protected array $injectedEnv = [
        'APPSYNC_HTTP_ENDPOINT' => 'https://envid.appsync-api.eu-west-1.amazonaws.com/event',
        'APPSYNC_REALTIME_ENDPOINT' => 'wss://envid.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime',
        'APPSYNC_CHANNEL_SIGNING_SECRET' => 'env-secret',
        'APPSYNC_CHANNEL_PREVIOUS_SIGNING_SECRETS' => 'env-old-secret, env-older-secret',
        'APPSYNC_PUBLIC_NAMESPACE' => 'env-pub',
        'APPSYNC_PRIVATE_NAMESPACE' => 'env-priv',
    ];

    /**
     * {@inheritDoc}
     */
    protected function resolveApplicationEnvironmentVariables($app): void
    {
        foreach ($this->injectedEnv as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        parent::resolveApplicationEnvironmentVariables($app);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        foreach ($this->injectedEnv as $key => $value) {
            unset($_SERVER[$key], $_ENV[$key]);
        }

        parent::tearDown();
    }
}
