<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Auth;

use InvalidArgumentException;

/**
 * Signs and verifies the HMAC tokens a Lambda authorizer accepts for
 * AppSync Events WebSocket connections and channel subscriptions.
 *
 * The wire format is `base64url(json(claims)) . '.' . hash_hmac('sha256',
 * base64urlPart, secret)` where the digest is lowercase hexadecimal and
 * base64url carries no padding. The tokens are shared secrets between the
 * broadcasting server (which issues them) and the Lambda authorizer (which
 * verifies them); AWS never sees the raw secret.
 *
 * Claims are `purpose` (`connect` or `subscribe`), `uid`, `exp` (Unix
 * timestamp) and, for subscribe tokens, `channel` (the AppSync channel
 * path the token is scoped to).
 *
 * @see https://docs.aws.amazon.com/appsync/latest/eventapi/configure-event-api-auth.html
 */
final class TokenSigner
{
    /**
     * Create a signer bound to a shared secret.
     *
     * @param  string  $secret  The HMAC secret shared with the Lambda authorizer.
     *
     * @throws InvalidArgumentException When the secret is empty.
     */
    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('The token signing secret must not be empty.');
        }
    }

    /**
     * Sign an arbitrary claims payload.
     *
     * @param  array<string, mixed>  $claims  The claims to encode into the token.
     * @return string The signed token in `<base64url-claims>.<hex-hmac>` form.
     *
     * @throws \JsonException When the claims cannot be JSON-encoded.
     */
    public function sign(array $claims): string
    {
        $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Verify a token and return its claims.
     *
     * @param  string  $token  The token as presented by the client.
     * @return array<string, mixed> The decoded claims.
     *
     * @throws InvalidTokenException When the token is malformed or the signature does not match.
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new InvalidTokenException('Invalid token format.');
        }

        [$payload, $signature] = $parts;

        $expected = hash_hmac('sha256', $payload, $this->secret);

        if (! hash_equals($expected, $signature)) {
            throw new InvalidTokenException('Invalid token signature.');
        }

        $json = base64_decode(self::base64UrlDecode($payload), true);

        if ($json === false) {
            throw new InvalidTokenException('Invalid token payload.');
        }

        $claims = json_decode($json, true);

        if (! is_array($claims)) {
            throw new InvalidTokenException('Invalid token payload.');
        }

        return $claims;
    }

    /**
     * Issue a token that authorizes a WebSocket connection (EVENT_CONNECT).
     *
     * @param  string  $uid  The identifier of the connecting user.
     * @param  int  $ttl  Seconds until the token expires.
     * @return string The signed connect token.
     *
     * @throws \JsonException When the claims cannot be JSON-encoded.
     */
    public function connectToken(string $uid, int $ttl = 43200): string
    {
        return $this->sign([
            'purpose' => 'connect',
            'uid' => $uid,
            'exp' => time() + $ttl,
        ]);
    }

    /**
     * Issue a token that authorizes a subscription to a single channel path.
     *
     * @param  string  $uid  The identifier of the subscribing user.
     * @param  string  $channelPath  The AppSync channel path the token is scoped to.
     * @param  int  $ttl  Seconds until the token expires.
     * @return string The signed subscribe token.
     *
     * @throws \JsonException When the claims cannot be JSON-encoded.
     */
    public function subscribeToken(string $uid, string $channelPath, int $ttl = 3600): string
    {
        return $this->sign([
            'purpose' => 'subscribe',
            'channel' => $channelPath,
            'uid' => $uid,
            'exp' => time() + $ttl,
        ]);
    }

    /**
     * Encode raw bytes as base64url without padding (RFC 4648 §5).
     *
     * @param  string  $data  The bytes to encode.
     * @return string The base64url representation.
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Convert base64url back to a padded standard base64 string.
     *
     * @param  string  $data  The base64url input.
     * @return string The equivalent padded base64 string.
     */
    private static function base64UrlDecode(string $data): string
    {
        return strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4);
    }
}
