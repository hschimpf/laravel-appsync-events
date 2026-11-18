<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Lambda\AppSyncAuthorizer;

it('authorizes tokens issued by the app signer and returns the configured ttl', function (): void {
    config()->set('appsync-events.signing_secret', 'shared-secret');
    config()->set('appsync-events.authorizer.cache_ttl', 600);

    $token = app(TokenSigner::class)->connectToken('u-9');

    $result = app(AppSyncAuthorizer::class)->authorize([
        'authorizationToken' => $token,
        'requestContext' => ['operation' => 'EVENT_CONNECT'],
    ]);

    expect($result['isAuthorized'])->toBeTrue()
        ->and($result['ttlOverride'])->toBe(600)
        ->and($result['handlerContext']['uid'])->toBe('u-9');
});
