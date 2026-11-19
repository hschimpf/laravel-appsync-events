<?php

declare(strict_types=1);

use HDSSolutions\Laravel\AppSyncEvents\Auth\InvalidTokenException;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;

it('round-trips signed claims', function (): void {
    $signer = new TokenSigner('secret');

    $claims = ['purpose' => 'connect', 'uid' => 'u-1', 'exp' => 123];

    expect($signer->verify($signer->sign($claims)))->toBe($claims);
});

it('produces the expected wire format', function (): void {
    // Literal computed once with the reference algorithm for this exact
    // payload and secret; guards the wire format against regressions.
    $expected = 'eyJwdXJwb3NlIjoiY29ubmVjdCIsInVpZCI6InUtMSIsImV4cCI6MjAwMDAwMDAwMH0'
        .'.98d239ae8a0006177235c96cad2074ad3b2400a334c2267b55a2e63925be4acb';

    $token = (new TokenSigner('test-secret'))->sign([
        'purpose' => 'connect',
        'uid' => 'u-1',
        'exp' => 2000000000,
    ]);

    expect($token)->toBe($expected)
        ->and((new TokenSigner('test-secret'))->verify($expected))
        ->toBe(['purpose' => 'connect', 'uid' => 'u-1', 'exp' => 2000000000]);
});

it('rejects an empty secret', function (): void {
    new TokenSigner('');
})->throws(InvalidArgumentException::class);

it('rejects a token with a tampered payload', function (): void {
    $signer = new TokenSigner('secret');
    [$payload, $signature] = explode('.', $signer->sign(['uid' => 'u-1']));

    $signer->verify('AAAA'.$payload.'.'.$signature);
})->throws(InvalidTokenException::class);

it('rejects a token with a tampered signature', function (): void {
    $signer = new TokenSigner('secret');
    [$payload] = explode('.', $signer->sign(['uid' => 'u-1']));

    $signer->verify($payload.'.'.str_repeat('0', 64));
})->throws(InvalidTokenException::class);

it('rejects a token verified with the wrong secret', function (): void {
    $token = (new TokenSigner('secret-a'))->sign(['uid' => 'u-1']);

    (new TokenSigner('secret-b'))->verify($token);
})->throws(InvalidTokenException::class);

it('rejects malformed tokens', function (string $token): void {
    (new TokenSigner('secret'))->verify($token);
})->with([
    'no separator' => ['nodothere'],
    'three parts' => ['one.two.three'],
    'empty token' => [''],
])->throws(InvalidTokenException::class);

it('rejects well-signed but undecodable payloads', function (string $payload): void {
    // Forged with a valid signature so verification reaches the payload checks.
    $signature = hash_hmac('sha256', $payload, 'secret');

    (new TokenSigner('secret'))->verify($payload.'.'.$signature);
})->with([
    'invalid base64url characters' => ['a!b'],
    'valid base64url, invalid json' => [rtrim(strtr(base64_encode('{oops'), '+/', '-_'), '=')],
    'valid json, but not an object' => [rtrim(strtr(base64_encode('42'), '+/', '-_'), '=')],
])->throws(InvalidTokenException::class);

it('issues connect tokens with connect claims', function (): void {
    $signer = new TokenSigner('secret');

    $claims = $signer->verify($signer->connectToken('user-7'));

    expect($claims['purpose'])->toBe('connect')
        ->and($claims['uid'])->toBe('user-7')
        ->and($claims['exp'])->toBeInt()->toBeGreaterThan(time())
        ->and($claims)->not->toHaveKey('channel');
});

it('issues subscribe tokens with subscribe claims', function (): void {
    $signer = new TokenSigner('secret');

    $claims = $signer->verify($signer->subscribeToken('user-7', '/default/private/chat', 60));

    expect($claims['purpose'])->toBe('subscribe')
        ->and($claims['channel'])->toBe('/default/private/chat')
        ->and($claims['uid'])->toBe('user-7')
        ->and($claims['exp'])->toBeInt()->toBeLessThanOrEqual(time() + 60);
});

it('honours custom connect token ttl', function (): void {
    $claims = (new TokenSigner('secret'))->verify(
        (new TokenSigner('secret'))->connectToken('u-1', 10),
    );

    expect($claims['exp'])->toBeLessThanOrEqual(time() + 10);
});

it('verifies tokens signed with a previous secret', function (): void {
    $rotated = new TokenSigner('new-secret', 'old-secret');

    $claims = $rotated->verify((new TokenSigner('old-secret'))->connectToken('u-1'));

    expect($claims['uid'])->toBe('u-1');
});

it('rejects tokens signed with a secret that is not listed', function (): void {
    $rotated = new TokenSigner('new-secret', 'old-secret');

    $rotated->verify((new TokenSigner('other-secret'))->connectToken('u-1'));
})->throws(InvalidTokenException::class, 'Invalid token signature.');

it('denies a previous-secret token when the secret is not listed', function (): void {
    $signer = new TokenSigner('new-secret');

    $signer->verify((new TokenSigner('old-secret'))->connectToken('u-1'));
})->throws(InvalidTokenException::class, 'Invalid token signature.');

it('signs only with the primary secret', function (): void {
    $token = (new TokenSigner('new-secret', 'old-secret'))->connectToken('u-1');

    expect((new TokenSigner('new-secret'))->verify($token)['uid'])->toBe('u-1');
    expect(static fn () => (new TokenSigner('old-secret'))->verify($token))
        ->toThrow(InvalidTokenException::class);
});

it('normalizes previous secrets', function (array|string|null $secrets, array $expected): void {
    expect(TokenSigner::normalizeSecrets($secrets))->toBe($expected);
})->with([
    'comma-separated string' => ['a,b', ['a', 'b']],
    'whitespace-padded entries' => [' a , b ', ['a', 'b']],
    'empty entries' => ['a,,b,', ['a', 'b']],
    'empty string' => ['', []],
    'null' => [null, []],
    'empty array' => [[], []],
    'array with blanks' => [['a', ' ', ''], ['a']],
    'non-string entries' => [['a', 42, null], ['a']],
    'duplicates' => ['a,b,a', ['a', 'b']],
    'order preserved' => ['c,a,b', ['c', 'a', 'b']],
]);

it('never accepts a signature forged with an empty key', function (array|string $previous): void {
    $payload = rtrim(strtr(base64_encode('{"purpose":"connect","uid":"u-1","exp":4102444800}'), '+/', '-_'), '=');
    $forged = $payload.'.'.hash_hmac('sha256', $payload, '');

    (new TokenSigner('secret', $previous))->verify($forged);
})->with([
    'trailing comma' => ['old-secret,'],
    'double comma' => ['old-secret,,'],
    'empty array entry' => [['old-secret', '']],
    'whitespace-only entry' => [['old-secret', '  ']],
])->throws(InvalidTokenException::class);
