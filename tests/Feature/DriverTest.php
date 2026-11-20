<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\InvalidTokenException;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Broadcasting\AppSyncBroadcaster;
use HDSSolutions\Laravel\AppSyncEvents\Exceptions\ConfigurationException;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Fixtures\TestUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function (): void {
    config()->set('appsync-events.http_endpoint', 'https://defaultid.appsync-api.us-east-1.amazonaws.com/event');
    config()->set('appsync-events.signing_secret', 'default-secret');
});

it('resolves an AppSyncBroadcaster for the appsync connection', function (): void {
    expect(Broadcast::connection('appsync'))->toBeInstanceOf(AppSyncBroadcaster::class);
});

it('resolves the appsync driver as the default broadcasting connection', function (): void {
    config()->set('broadcasting.default', 'appsync');

    expect(Broadcast::connection())->toBeInstanceOf(AppSyncBroadcaster::class);
});

it('honours per-connection overrides', function (): void {
    config()->set('broadcasting.connections.appsync', [
        'driver' => 'appsync',
        'http_endpoint' => 'https://customid.appsync-api.ap-southeast-2.amazonaws.com/event',
        'signing_secret' => 'connection-secret',
        'namespaces' => ['public' => 'tenant-pub', 'private' => 'tenant'],
    ]);

    $broadcaster = Broadcast::connection('appsync');

    // namespaces override flows into channel path resolution.
    expect($broadcaster->channelPath('private-solo'))->toBe('/tenant/solo')
        ->and($broadcaster->channelPath('solo'))->toBe('/tenant-pub/solo');

    // the subscribe token is signed with the connection secret, not the
    // package-wide one.
    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-orders.9']);
    $request->setUserResolver(static fn (): TestUser => new TestUser(7));

    $token = $broadcaster->validAuthenticationResponse($request, true)['auth'];

    $claims = (new TokenSigner('connection-secret'))->verify($token);
    expect($claims['uid'])->toBe('7')
        ->and($claims['channel'])->toBe('/tenant/orders/9');

    expect(static fn () => (new TokenSigner('default-secret'))->verify($token))
        ->toThrow(InvalidTokenException::class);
});

it('throws a ConfigurationException naming APPSYNC_HTTP_ENDPOINT when no endpoint is configured', function (): void {
    config()->set('appsync-events.http_endpoint', null);

    expect(static fn () => Broadcast::connection('appsync'))
        ->toThrow(ConfigurationException::class, 'APPSYNC_HTTP_ENDPOINT');
});

it('throws a ConfigurationException naming the signing secret when none is configured', function (): void {
    config()->set('appsync-events.signing_secret', null);

    expect(static fn () => Broadcast::connection('appsync'))
        ->toThrow(ConfigurationException::class, 'APPSYNC_CHANNEL_SIGNING_SECRET');
});
