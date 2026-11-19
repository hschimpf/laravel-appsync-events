<?php

declare(strict_types=1);

it('merges the package config defaults', function (): void {
    expect(config('appsync-events.namespaces.public'))->toBe('public')
        ->and(config('appsync-events.namespaces.private'))->toBe('private')
        ->and(config('appsync-events.tokens.connect_ttl'))->toBe(43200)
        ->and(config('appsync-events.tokens.subscribe_ttl'))->toBe(3600)
        ->and(config('appsync-events.authorizer.cache_ttl'))->toBe(300)
        ->and(config('appsync-events.http.timeout'))->toBe(2.0)
        ->and(config('appsync-events.http.connect_timeout'))->toBe(1.0)
        ->and(config('appsync-events.client.auth_endpoint'))->toBe('/broadcasting/auth')
        ->and(config('appsync-events.client.inactivity_timeout'))->toBe(10)
        ->and(config('appsync-events.routes.auth.enabled'))->toBeTrue()
        ->and(config('appsync-events.routes.auth.middleware'))->toBe(['web'])
        ->and(config('appsync-events.routes.client_config.enabled'))->toBeFalse()
        ->and(config('appsync-events.routes.client_config.uri'))->toBe('broadcasting/appsync')
        ->and(config('appsync-events.routes.client_config.middleware'))->toBe(['web', 'auth']);
});

it('defaults the appsync broadcasting connection to the appsync driver', function (): void {
    expect(config('broadcasting.connections.appsync'))->toBe(['driver' => 'appsync']);
});
