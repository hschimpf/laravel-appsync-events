<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Exceptions\ConfigurationException;
use HDSSolutions\Laravel\AppSyncEvents\Facades\AppSyncEvents;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\NameUidResolver;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    config()->set('appsync-events.http_endpoint', 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event');
    config()->set('appsync-events.realtime_endpoint', 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime');
    config()->set('appsync-events.signing_secret', 'client-secret');
});

it('returns the documented client configuration for a user', function (): void {
    $config = AppSyncEvents::clientConfig(new TestUser(5));

    expect($config)->toMatchArray([
        'wsEndpoint' => 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime',
        'httpHost' => 'abc123.appsync-api.eu-west-1.amazonaws.com',
        'authEndpoint' => '/broadcasting/auth',
        'defaultNamespace' => 'default',
        'inactivityTimeout' => 10,
    ]);

    $claims = (new TokenSigner('client-secret'))->verify($config['connectToken']);

    expect($claims['purpose'])->toBe('connect')
        ->and($claims['uid'])->toBe('5')
        ->and($claims['exp'])->toBeInt()
        ->toBeGreaterThanOrEqual(time() + 43190)
        ->toBeLessThanOrEqual(time() + 43210);
});

it('extracts the httpHost from a local endpoint including the port', function (): void {
    config()->set('appsync-events.http_endpoint', 'http://127.0.0.1:9401/event');

    expect(AppSyncEvents::httpHost('http://127.0.0.1:9401/event'))->toBe('127.0.0.1:9401')
        ->and(AppSyncEvents::clientConfig(new TestUser(5))['httpHost'])->toBe('127.0.0.1:9401');
});

it('returns null for guests', function (): void {
    expect(AppSyncEvents::clientConfig())->toBeNull();
});

it('uses the current authenticated user when none is given', function (): void {
    $this->actingAs(new TestUser(9));

    $claims = (new TokenSigner('client-secret'))->verify(AppSyncEvents::clientConfig()['connectToken']);

    expect($claims['uid'])->toBe('9');
});

it('honours resolveUidUsing for the uid claim', function (): void {
    AppSyncEvents::resolveUidUsing(static fn (object $user): string => 'custom-'.$user->id);

    $claims = (new TokenSigner('client-secret'))->verify(
        AppSyncEvents::clientConfig(new TestUser(5))['connectToken'],
    );

    expect($claims['uid'])->toBe('custom-5');
});

it('honours the uid_resolver invokable class-string', function (): void {
    config()->set('appsync-events.uid_resolver', NameUidResolver::class);

    $claims = (new TokenSigner('client-secret'))->verify(
        AppSyncEvents::clientConfig(new TestUser(5, 'jane'))['connectToken'],
    );

    expect($claims['uid'])->toBe('name-jane');
});

it('prefers resolveUidUsing over the uid_resolver config', function (): void {
    config()->set('appsync-events.uid_resolver', NameUidResolver::class);
    AppSyncEvents::resolveUidUsing(static fn (object $user): string => 'closure-'.$user->id);

    $claims = (new TokenSigner('client-secret'))->verify(
        AppSyncEvents::clientConfig(new TestUser(5, 'jane'))['connectToken'],
    );

    expect($claims['uid'])->toBe('closure-5');
});

it('honours a custom connect_ttl', function (): void {
    config()->set('appsync-events.tokens.connect_ttl', 60);

    $claims = (new TokenSigner('client-secret'))->verify(
        AppSyncEvents::clientConfig(new TestUser(5))['connectToken'],
    );

    expect($claims['exp'])->toBeGreaterThanOrEqual(time() + 55)->toBeLessThanOrEqual(time() + 65);
});

it('throws a ConfigurationException naming APPSYNC_REALTIME_ENDPOINT when missing', function (): void {
    config()->set('appsync-events.realtime_endpoint', null);

    expect(static fn () => AppSyncEvents::clientConfig(new TestUser(5)))
        ->toThrow(ConfigurationException::class, 'APPSYNC_REALTIME_ENDPOINT');
});

it('throws a ConfigurationException naming the signing secret when missing', function (): void {
    config()->set('appsync-events.signing_secret', null);

    expect(static fn () => AppSyncEvents::clientConfig(new TestUser(5)))
        ->toThrow(ConfigurationException::class, 'APPSYNC_CHANNEL_SIGNING_SECRET');
});
