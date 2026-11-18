<?php

declare(strict_types=1);

use Aws\Credentials\Credentials;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Broadcasting\AppSyncBroadcaster;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

function broadcasterWithMock(array $responses, array &$history, array $options = []): AppSyncBroadcaster
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new AppSyncBroadcaster(
        signer: new TokenSigner('test-secret'),
        httpEndpoint: $options['endpoint'] ?? 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event',
        region: $options['region'] ?? null,
        defaultNamespace: $options['defaultNamespace'] ?? 'default',
        uidResolver: $options['uidResolver'] ?? null,
        client: new Client(['handler' => $stack, 'http_errors' => false]),
        credentialProvider: $options['credentialProvider']
            ?? static fn () => Create::promiseFor(new Credentials('AKIDEXAMPLE', 'secret', 'session-token')),
    );
}

it('sends one signed POST per channel', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([new Response(200), new Response(200)], $history);

    $broadcaster->broadcast([
        new PrivateChannel('orders.1'),
        new Channel('orders.2'),
    ], 'OrderUpdated', ['id' => 1]);

    expect($history)->toHaveCount(2);

    [$requestA, $requestB] = array_column($history, 'request');

    expect($requestA->getMethod())->toBe('POST')
        ->and((string) $requestA->getUri())->toBe('https://abc123.appsync-api.eu-west-1.amazonaws.com/event');

    $bodyA = json_decode((string) $requestA->getBody(), true);
    expect($bodyA['channel'])->toBe('/orders/private/1')
        ->and($bodyA['events'])->toHaveCount(1);

    $messageA = json_decode($bodyA['events'][0], true);
    expect($messageA['event'])->toBe('OrderUpdated')
        ->and($messageA['channel'])->toBe('/orders/private/1')
        ->and($messageA['data'])->toBe(['id' => 1]);

    $bodyB = json_decode((string) $requestB->getBody(), true);
    expect($bodyB['channel'])->toBe('/orders/public/2');
});

it('signs requests with SigV4 for the endpoint region', function (): void {
    $history = [];
    broadcasterWithMock([new Response(200)], $history)
        ->broadcast([new Channel('orders')], 'Updated', []);

    $authorization = $history[0]['request']->getHeaderLine('Authorization');

    expect($authorization)->toStartWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/')
        ->toContain('/eu-west-1/appsync/aws4_request')
        ->and($history[0]['request']->getHeaderLine('X-Amz-Security-Token'))->toBe('session-token');
});

it('uses the constructor region and us-east-1 for non-appsync hosts', function (string $endpoint, ?string $region, string $expected): void {
    $history = [];
    broadcasterWithMock([new Response(200)], $history, ['endpoint' => $endpoint, 'region' => $region])
        ->broadcast([new Channel('orders')], 'Updated', []);

    expect($history[0]['request']->getHeaderLine('Authorization'))
        ->toContain("/{$expected}/appsync/aws4_request");
})->with([
    'constructor region' => ['https://events.example.com/event', 'ap-south-1', 'ap-south-1'],
    'default us-east-1' => ['http://127.0.0.1:9400/event', null, 'us-east-1'],
]);

it('sends a Host header including the port for local endpoints', function (): void {
    $history = [];
    broadcasterWithMock([new Response(200)], $history, ['endpoint' => 'http://127.0.0.1:9400/event'])
        ->broadcast([new Channel('orders')], 'Updated', []);

    expect($history[0]['request']->getHeaderLine('Host'))->toBe('127.0.0.1:9400');
});

it('throws a BroadcastException mentioning path and status on non-2xx', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([new Response(500)], $history);

    try {
        $broadcaster->broadcast([new PrivateChannel('orders.1')], 'Updated', []);
        $this->fail('Expected BroadcastException');
    } catch (BroadcastException $e) {
        expect($e->getMessage())->toContain('/orders/private/1')->toContain('500');
    }
});

it('wraps connection errors in a BroadcastException with the previous', function (): void {
    $history = [];
    $request = new Psr7Request('POST', 'https://abc123.appsync-api.eu-west-1.amazonaws.com/event');
    $broadcaster = broadcasterWithMock(
        [new ConnectException('connection refused', $request, null)],
        $history,
    );

    try {
        $broadcaster->broadcast([new Channel('orders')], 'Updated', []);
        $this->fail('Expected BroadcastException');
    } catch (BroadcastException $e) {
        expect($e->getPrevious())->toBeInstanceOf(ConnectException::class)
            ->and($e->getMessage())->toContain('/default/public/orders');
    }
});

it('sends no request for an empty channel list', function (): void {
    $history = [];
    broadcasterWithMock([], $history)->broadcast([], 'Updated', []);

    expect($history)->toBeEmpty();
});

it('resolves the credential provider once per broadcast call', function (): void {
    $history = [];
    $calls = 0;
    $provider = static function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Credentials('AKIDEXAMPLE', 'secret'));
    };

    broadcasterWithMock([new Response(200), new Response(200)], $history, ['credentialProvider' => $provider])
        ->broadcast([new Channel('a'), new Channel('b')], 'Updated', []);

    expect($calls)->toBe(1);
});

it('maps channel names to AppSync channel paths', function (): void {
    $broadcaster = new AppSyncBroadcaster(new TokenSigner('s'), 'https://x.example/event', defaultNamespace: 'app');

    expect($broadcaster->channelPath('private-orders.1'))->toBe('/orders/private/1')
        ->and($broadcaster->channelPath('plain'))->toBe('/app/public/plain');
});

function authRequest(string $channelName, ?GenericUser $user): Request
{
    $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channelName]);
    $request->setUserResolver(static fn () => $user);

    return $request;
}

it('throws AccessDenied for an empty channel name', function (): void {
    $history = [];
    broadcasterWithMock([], $history)->auth(authRequest('', new GenericUser(['id' => 1])));
})->throws(AccessDeniedHttpException::class);

it('throws AccessDenied for a guarded channel without a user', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([], $history);
    $broadcaster->channel('private.{id}', static fn ($user, $id): bool => true);

    $broadcaster->auth(authRequest('private-private.abc', null));
})->throws(AccessDeniedHttpException::class);

it('throws AccessDenied when the channel callback denies', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([], $history);
    $broadcaster->channel('private.{id}', static fn ($user, $id): bool => false);

    $broadcaster->auth(authRequest('private-private.abc', new GenericUser(['id' => 1])));
})->throws(AccessDeniedHttpException::class);

it('returns a verifiable subscribe token for an authorized channel', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([], $history);
    $broadcaster->channel('private.{id}', static fn ($user, $id): bool => $id === 'abc');

    $response = $broadcaster->auth(authRequest('private-private.abc', new GenericUser(['id' => 42])));

    $claims = (new TokenSigner('test-secret'))->verify($response['auth']);

    expect($claims['purpose'])->toBe('subscribe')
        ->and($claims['channel'])->toBe('/private/private/abc')
        ->and($claims['uid'])->toBe('42')
        ->and($claims['exp'])->toBeGreaterThan(time());
});

it('honours a custom uid resolver and default namespace', function (): void {
    $history = [];
    $broadcaster = broadcasterWithMock([], $history, [
        'defaultNamespace' => 'app',
        'uidResolver' => static fn ($user): string => 'uuid-'.$user->getAuthIdentifier(),
    ]);
    $broadcaster->channel('room', static fn ($user): bool => true);

    $response = $broadcaster->auth(authRequest('room', new GenericUser(['id' => 7])));

    $claims = (new TokenSigner('test-secret'))->verify($response['auth']);

    expect($claims['uid'])->toBe('uuid-7')
        ->and($claims['channel'])->toBe('/app/public/room');
});
