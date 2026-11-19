<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Lambda;

use HDSSolutions\Laravel\AppSyncEvents\Auth\InvalidTokenException;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;

/**
 * Verifies AppSync Events Lambda-authorizer events against the shared
 * HMAC signing secret (see {@see TokenSigner} for the token wire format).
 *
 * The class is intentionally framework-agnostic — it takes the secret
 * through the constructor and reads nothing from config(), the container
 * or the environment — so it can be invoked from any PHP Lambda runtime
 * with a single line:
 *
 *     return (new AppSyncAuthorizer($_ENV['APPSYNC_SIGNING_SECRET']))->authorize($event);
 *
 * Authorization rules:
 *  - `EVENT_CONNECT` requires a verified token with purpose `connect`,
 *    not expired.
 *  - `EVENT_SUBSCRIBE` requires a verified token with purpose `subscribe`,
 *    not expired, whose `channel` claim is a non-empty string equal to the
 *    channel the client is subscribing to (`requestContext.channel`).
 *  - `EVENT_PUBLISH` and any unknown operation are always denied —
 *    publishing is a server-side concern performed with IAM credentials,
 *    never with these tokens.
 *
 * The response shape follows the AppSync Events Lambda authorizer
 * contract: `isAuthorized` plus `ttlOverride` (the connection TTL
 * AppSync applies — the configured cache TTL when authorized, 0 when
 * denied) and, only when authorized, `handlerContext` with the token's
 * `uid` and `purpose` for downstream handlers.
 *
 * @see https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-lambda-authorizers.html
 */
final class AppSyncAuthorizer
{
    /**
     * The signer, created lazily so an empty secret denies requests
     * instead of failing at construction time.
     */
    private ?TokenSigner $signer = null;

    /**
     * @param  string  $signingSecret  The HMAC secret shared with the token issuer.
     * @param  int  $cacheTtl  `ttlOverride` (seconds) returned on authorized calls.
     */
    public function __construct(
        private readonly string $signingSecret,
        private readonly int $cacheTtl = 300,
    ) {}

    /**
     * Authorize one AppSync Lambda-authorizer event.
     *
     * @param  array<string, mixed>  $event  The authorizer event; reads
     *                                       `authorizationToken`,
     *                                       `requestContext.operation` and
     *                                       `requestContext.channel`. Missing keys deny.
     * @return array{isAuthorized: bool, ttlOverride: int, handlerContext?: array{uid: string, purpose: string}}
     */
    public function authorize(array $event): array
    {
        $token = $event['authorizationToken'] ?? null;
        $operation = $event['requestContext']['operation'] ?? null;
        $channel = $event['requestContext']['channel'] ?? null;

        $claims = [];
        $authorized = false;

        if (is_string($token) && $token !== '' && $this->signer() !== null) {
            try {
                $claims = $this->signer()->verify($token);
            } catch (InvalidTokenException) {
                $claims = [];
            }

            if ($claims !== []) {
                $authorized = match ($operation) {
                    'EVENT_CONNECT' => ($claims['purpose'] ?? null) === 'connect'
                        && self::isNotExpired($claims),
                    'EVENT_SUBSCRIBE' => ($claims['purpose'] ?? null) === 'subscribe'
                        && self::isNotExpired($claims)
                        && is_string($claims['channel'] ?? null)
                        && $claims['channel'] !== ''
                        && $channel === $claims['channel'],
                    default => false,
                };
            }
        }

        $response = [
            'isAuthorized' => $authorized,
            'ttlOverride' => $authorized ? $this->cacheTtl : 0,
        ];

        if ($authorized) {
            $response['handlerContext'] = [
                'uid' => (string) ($claims['uid'] ?? ''),
                'purpose' => (string) ($claims['purpose'] ?? ''),
            ];
        }

        return $response;
    }

    /**
     * Lazily build the token signer; `null` when no secret is configured.
     *
     * @return TokenSigner|null The shared-secret signer, or null for an empty secret.
     */
    private function signer(): ?TokenSigner
    {
        return $this->signer ??= $this->signingSecret === ''
            ? null
            : new TokenSigner($this->signingSecret);
    }

    /**
     * Whether the claims carry an `exp` timestamp in the future.
     *
     * @param  array<string, mixed>  $claims  The verified token claims.
     */
    private static function isNotExpired(array $claims): bool
    {
        return isset($claims['exp']) && time() < (int) $claims['exp'];
    }
}
