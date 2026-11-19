<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Lambda\AppSyncAuthorizer;

const AUTHORIZER_SECRET = 'authorizer-secret';

function authorizerEvent(string $operation, string $token = '', ?string $channel = null): array
{
    $event = [
        'authorizationToken' => $token,
        'requestContext' => ['operation' => $operation],
    ];

    if ($channel !== null) {
        $event['requestContext']['channel'] = $channel;
    }

    return $event;
}

it('authorizes a connect token for EVENT_CONNECT', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeTrue()
        ->and($result['ttlOverride'])->toBe(300)
        ->and($result['handlerContext'])->toBe(['uid' => 'user-1', 'purpose' => 'connect']);
});

it('denies a subscribe token for EVENT_CONNECT', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))
        ->subscribeToken('user-1', '/default/private/chat');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0)
        ->and($result)->not->toHaveKey('handlerContext');
});

it('authorizes a subscribe token for the matching channel', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))
        ->subscribeToken('user-2', '/default/private/chat');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_SUBSCRIBE', $token, '/default/private/chat'));

    expect($result['isAuthorized'])->toBeTrue()
        ->and($result['handlerContext']['uid'])->toBe('user-2')
        ->and($result['handlerContext']['purpose'])->toBe('subscribe');
});

it('denies a subscribe token for a different channel', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))
        ->subscribeToken('user-2', '/default/private/chat');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_SUBSCRIBE', $token, '/default/private/other'));

    expect($result['isAuthorized'])->toBeFalse();
});

it('denies a subscribe token without a channel claim', function (?string $channel): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->sign([
        'purpose' => 'subscribe',
        'uid' => 'user-9',
        'exp' => time() + 300,
    ]);

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_SUBSCRIBE', $token, $channel));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
})->with([
    'channel requested' => ['/default/private/chat'],
    'no channel requested' => [null],
]);

it('denies a connect token for EVENT_SUBSCRIBE', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_SUBSCRIBE', $token, '/default/public/x'));

    expect($result['isAuthorized'])->toBeFalse();
});

it('denies expired tokens for connect and subscribe', function (string $operation, string $channel): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->sign([
        'purpose' => $operation === 'EVENT_CONNECT' ? 'connect' : 'subscribe',
        'channel' => $channel,
        'uid' => 'user-1',
        'exp' => time() - 10,
    ]);

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent($operation, $token, $channel));

    expect($result['isAuthorized'])->toBeFalse();
})->with([
    'connect' => ['EVENT_CONNECT', '/default/private/chat'],
    'subscribe' => ['EVENT_SUBSCRIBE', '/default/private/chat'],
]);

it('always denies EVENT_PUBLISH, even with a valid token', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_PUBLISH', $token, '/default/public/x'));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
});

it('denies unknown operations', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_UNKNOWN', $token));

    expect($result['isAuthorized'])->toBeFalse();
});

it('denies when the secret is empty', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(''))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
});

it('denies when event keys are missing', function (array $event): void {
    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))->authorize($event);

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
})->with([
    'empty event' => [[]],
    'no authorizationToken' => [['requestContext' => ['operation' => 'EVENT_CONNECT']]],
    'no requestContext' => [['authorizationToken' => 'x']],
    'empty token' => [['authorizationToken' => '', 'requestContext' => ['operation' => 'EVENT_CONNECT']]],
]);

it('denies a token signed with a different secret', function (): void {
    $token = (new TokenSigner('other-secret'))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeFalse();
});

it('honours a custom cache ttl', function (): void {
    $token = (new TokenSigner(AUTHORIZER_SECRET))->connectToken('user-1');

    $result = (new AppSyncAuthorizer(AUTHORIZER_SECRET, 60))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['ttlOverride'])->toBe(60);
});

it('authorizes tokens signed with a previous secret', function (array|string $previous): void {
    $authorizer = new AppSyncAuthorizer('new-secret', 300, $previous);
    $oldSigner = new TokenSigner('old-secret');

    $connect = $authorizer->authorize(
        authorizerEvent('EVENT_CONNECT', $oldSigner->connectToken('user-1')),
    );
    $subscribe = $authorizer->authorize(
        authorizerEvent('EVENT_SUBSCRIBE', $oldSigner->subscribeToken('user-1', '/orders/private/1'), '/orders/private/1'),
    );

    expect($connect['isAuthorized'])->toBeTrue()
        ->and($subscribe['isAuthorized'])->toBeTrue()
        ->and($subscribe['handlerContext']['uid'])->toBe('user-1');
})->with([
    'string form' => ['old-secret'],
    'array form' => [['old-secret']],
    'comma-separated form' => ['older-secret, old-secret'],
]);

it('denies a previous-secret token when it is not listed', function (): void {
    $token = (new TokenSigner('old-secret'))->connectToken('user-1');

    $result = (new AppSyncAuthorizer('new-secret'))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
});

it('denies everything for an empty primary secret even with previous secrets', function (): void {
    $token = (new TokenSigner('old-secret'))->connectToken('user-1');

    $result = (new AppSyncAuthorizer('', 300, 'old-secret'))
        ->authorize(authorizerEvent('EVENT_CONNECT', $token));

    expect($result['isAuthorized'])->toBeFalse()
        ->and($result['ttlOverride'])->toBe(0);
});
