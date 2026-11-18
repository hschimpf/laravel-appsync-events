<?php

declare(strict_types=1);

use Aws\Credentials\Credentials;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Broadcasting\AppSyncBroadcaster;
use HDSSolutions\Laravel\AppSyncEvents\Lambda\AppSyncAuthorizer;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator\EmulatorSuite;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->api = EmulatorSuite::requireApi($this);
});

it('fails publishing to a channel whose namespace does not exist', function (): void {
    $broadcaster = new AppSyncBroadcaster(
        signer: new TokenSigner(EmulatorSuite::SIGNING_SECRET),
        httpEndpoint: $this->api->httpEndpoint,
        region: 'us-east-1',
        credentialProvider: new Credentials('emulator-akid', 'emulator-secret'),
    );

    try {
        $broadcaster->broadcast([new Channel('ghost.1')], 'Boo', []);
        $this->fail('Expected BroadcastException');
    } catch (BroadcastException $exception) {
        // The emulator answers 404 NotFoundException for unknown namespaces.
        expect($exception->getMessage())
            ->toContain('/ghost/public/1')
            ->toContain('404');
    }
});

it('fails publishing to an unreachable endpoint', function (): void {
    $broadcaster = new AppSyncBroadcaster(
        signer: new TokenSigner(EmulatorSuite::SIGNING_SECRET),
        httpEndpoint: 'http://127.0.0.1:9/event', // discard port: always refused
        region: 'us-east-1',
        credentialProvider: new Credentials('emulator-akid', 'emulator-secret'),
        connectTimeout: 1.0,
    );

    try {
        $broadcaster->broadcast([new Channel('orders.1')], 'Boo', []);
        $this->fail('Expected BroadcastException');
    } catch (BroadcastException $exception) {
        expect($exception->getMessage())->toContain('/orders/public/1');
    }
});

it('verifies issued subscribe tokens against the channel path (contract check)', function (): void {
    // NOT an emulator check — the emulator never invokes the Lambda
    // authorizer. This asserts that the token the broadcaster issues for
    // a channel is accepted for exactly that channel path by the real
    // AppSyncAuthorizer (which AWS would invoke in production).
    $signer = new TokenSigner(EmulatorSuite::SIGNING_SECRET);
    $broadcaster = new AppSyncBroadcaster(
        signer: $signer,
        httpEndpoint: $this->api->httpEndpoint,
        region: 'us-east-1',
        credentialProvider: new Credentials('emulator-akid', 'emulator-secret'),
    );
    $broadcaster->channel('orders.{id}', static fn ($user, $id): bool => true);

    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-orders.9']);
    $request->setUserResolver(static fn () => new GenericUser(['id' => 7]));
    $token = $broadcaster->validAuthenticationResponse($request, $broadcaster->auth($request))['auth'];

    $authorizer = new AppSyncAuthorizer(EmulatorSuite::SIGNING_SECRET);

    $authorized = $authorizer->authorize([
        'authorizationToken' => $token,
        'requestContext' => ['operation' => 'EVENT_SUBSCRIBE', 'channel' => '/orders/private/9'],
    ]);
    expect($authorized['isAuthorized'])->toBeTrue()
        ->and($authorized['handlerContext']['uid'])->toBe('7');

    $wrongChannel = $authorizer->authorize([
        'authorizationToken' => $token,
        'requestContext' => ['operation' => 'EVENT_SUBSCRIBE', 'channel' => '/orders/private/10'],
    ]);
    expect($wrongChannel['isAuthorized'])->toBeFalse()
        ->and($wrongChannel['ttlOverride'])->toBe(0);
});
