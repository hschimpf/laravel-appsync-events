<?php

declare(strict_types=1);

use Aws\Credentials\Credentials;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Broadcasting\AppSyncBroadcaster;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator\EmulatorEventApi;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator\EmulatorSuite;
use HDSSolutions\Laravel\AppSyncEvents\Tests\Support\Emulator\NodeSubscriber;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;

/**
 * End-to-end delivery tests: the real {@see AppSyncBroadcaster}
 * publishes through the emulator and the real built JS connector
 * (js/dist/index.js, running under Node) receives the frames.
 */
function emuBroadcaster(EmulatorEventApi $api): AppSyncBroadcaster
{
    return new AppSyncBroadcaster(
        signer: new TokenSigner(EmulatorSuite::SIGNING_SECRET),
        httpEndpoint: $api->httpEndpoint,
        region: 'us-east-1',
        credentialProvider: new Credentials('emulator-akid', 'emulator-secret'),
    );
}

function emuAuthRequest(string $channelName, GenericUser $user): Request
{
    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channelName]);
    $request->setUserResolver(static fn () => $user);

    return $request;
}

beforeEach(function (): void {
    $this->api = EmulatorSuite::requireApi($this);
});

it('delivers a public broadcast to an API-key subscriber', function (): void {
    $subscriber = new NodeSubscriber([
        'wsEndpoint' => $this->api->realtimeEndpoint,
        'httpHost' => $this->api->httpHost,
        'publicApiKey' => $this->api->apiKey,
        'channels' => [['name' => 'orders.pub-1', 'event' => '.OrderShipped']],
    ]);

    try {
        $subscriber->waitFor('ready');

        emuBroadcaster($this->api)->broadcast(
            [new Channel('orders.pub-1')],
            'OrderShipped',
            ['order' => 7],
        );

        $line = $subscriber->waitFor('event', static fn (array $l): bool => $l['channel'] === 'orders.pub-1');

        // `/public/orders/pub-1` proves the channel path mapping is what
        // the emulator routed: the `public` namespace, then the name
        // segments.
        expect($line['data'])->toBe(['order' => 7]);
    } finally {
        $subscriber->close();
    }
});

it('delivers a private broadcast to a token subscriber', function (): void {
    $broadcaster = emuBroadcaster($this->api);
    $broadcaster->channel('orders.{id}', static fn ($user, $id): bool => true);

    $signer = new TokenSigner(EmulatorSuite::SIGNING_SECRET);
    $request = emuAuthRequest('private-orders.priv-1', new GenericUser(['id' => 42]));
    $subscribeToken = $broadcaster->validAuthenticationResponse($request, $broadcaster->auth($request))['auth'];

    $subscriber = new NodeSubscriber([
        'wsEndpoint' => $this->api->realtimeEndpoint,
        'httpHost' => $this->api->httpHost,
        'connectToken' => $signer->connectToken('u-42'),
        'subscribeTokens' => ['private-orders.priv-1' => $subscribeToken],
        'channels' => [['name' => 'private-orders.priv-1', 'event' => '.OrderShipped']],
    ]);

    try {
        $subscriber->waitFor('ready');

        $broadcaster->broadcast(
            [new PrivateChannel('orders.priv-1')],
            'OrderShipped',
            ['order' => 11],
        );

        $line = $subscriber->waitFor('event', static fn (array $l): bool => $l['channel'] === 'private-orders.priv-1');

        expect($line['data'])->toBe(['order' => 11]);
    } finally {
        $subscriber->close();
    }
});

it('routes broadcasts only to subscribers of their channel', function (): void {
    $subscriber = new NodeSubscriber([
        'wsEndpoint' => $this->api->realtimeEndpoint,
        'httpHost' => $this->api->httpHost,
        'publicApiKey' => $this->api->apiKey,
        'channels' => [
            ['name' => 'orders.iso-a', 'event' => '.IsoPing'],
            ['name' => 'orders.iso-b', 'event' => '.IsoPing'],
        ],
    ]);

    try {
        $subscriber->waitFor('ready');
        $broadcaster = emuBroadcaster($this->api);

        // Both subscribers receive their own channel's events.
        $broadcaster->broadcast([new Channel('orders.iso-a')], 'IsoPing', ['to' => 'a']);
        $broadcaster->broadcast([new Channel('orders.iso-b')], 'IsoPing', ['to' => 'b']);

        $subscriber->waitFor('event', static fn (array $l): bool => $l['channel'] === 'orders.iso-a' && $l['data'] === ['to' => 'a']);
        $subscriber->waitFor('event', static fn (array $l): bool => $l['channel'] === 'orders.iso-b' && $l['data'] === ['to' => 'b']);

        // An event published to one channel is not delivered to the other
        // (bounded quiet period, mirroring the emulator's own tests).
        $broadcaster->broadcast([new Channel('orders.iso-a')], 'IsoPing', ['to' => 'only-a']);

        $line = $subscriber->waitFor('event', static fn (array $l): bool => $l['data'] === ['to' => 'only-a']);
        expect($line['channel'])->toBe('orders.iso-a');

        $stray = array_filter(
            $subscriber->collect(500),
            static fn (array $l): bool => $l['type'] === 'event' && $l['channel'] === 'orders.iso-b',
        );
        expect($stray)->toBeEmpty();
    } finally {
        $subscriber->close();
    }
});

it('delivers notification-style event names intact', function (): void {
    $event = 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';

    $subscriber = new NodeSubscriber([
        'wsEndpoint' => $this->api->realtimeEndpoint,
        'httpHost' => $this->api->httpHost,
        'publicApiKey' => $this->api->apiKey,
        'channels' => [['name' => 'notify.n1', 'event' => ".{$event}"]],
    ]);

    try {
        $subscriber->waitFor('ready');

        emuBroadcaster($this->api)->broadcast(
            [new Channel('notify.n1')],
            $event,
            ['id' => 'n-1', 'type' => 'InvoicePaid'],
        );

        $line = $subscriber->waitFor('event', static fn (array $l): bool => $l['channel'] === 'notify.n1');

        expect($line['data'])->toBe(['id' => 'n-1', 'type' => 'InvoicePaid']);
    } finally {
        $subscriber->close();
    }
});
