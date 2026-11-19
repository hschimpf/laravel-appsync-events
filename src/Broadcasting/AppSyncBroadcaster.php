<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents\Broadcasting;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Lambda\AppSyncAuthorizer;
use HDSSolutions\Laravel\AppSyncEvents\Support\ChannelPath;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Laravel broadcasting driver that publishes events to AWS AppSync Events
 * over its HTTP publish endpoint (`POST {endpoint}/event` style) signed
 * with SigV4, and that issues per-channel HMAC subscribe tokens for the
 * broadcasting authentication endpoint (`/broadcasting/auth`).
 *
 * Publishing uses the IAM authorization mode of the Event API; connecting
 * and subscribing is authorized by a Lambda authorizer that verifies the
 * tokens issued here (see {@see AppSyncAuthorizer}).
 *
 * The sender's socket id (from `->toOthers()`) is published as a
 * top-level `socket` field of the event envelope — outside `data` —
 * so clients can exclude their own events.
 *
 * @see https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-websocket-protocol.html
 */
final class AppSyncBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * The lazily created HTTP client, when none was injected.
     */
    private ?ClientInterface $resolvedClient = null;

    /**
     * The lazily created memoized credential provider, when none was injected.
     *
     * @var callable|null
     */
    private $resolvedCredentialProvider = null;

    /**
     * @param  TokenSigner  $signer  The signer issuing per-channel subscribe tokens.
     * @param  string  $httpEndpoint  The Event API HTTP publish endpoint
     *                                (e.g. `https://<id>.appsync-api.<region>.amazonaws.com/event`;
     *                                `http://host:port/event` is accepted for local testing).
     * @param  string|null  $region  AWS region used for signing when it cannot be
     *                               parsed from the endpoint host; defaults to `us-east-1`.
     * @param  string  $defaultNamespace  AppSync channel namespace for channel
     *                                    names without a `.`-separated namespace part.
     * @param  int  $subscribeTokenTtl  Lifetime in seconds of issued subscribe tokens.
     * @param  Closure|null  $uidResolver  Maps an authenticated user to the `uid`
     *                                     claim; defaults to `getAuthIdentifier()`.
     * @param  ClientInterface|null  $client  HTTP client for publish requests;
     *                                        a default one is created lazily.
     * @param  mixed  $credentialProvider  A callable returning an AWS credentials
     *                                     promise, or a CredentialsInterface; defaults
     *                                     to a memoized default provider chain.
     * @param  float  $timeout  Request timeout for publish calls.
     * @param  float  $connectTimeout  Connect timeout for publish calls.
     */
    public function __construct(
        private readonly TokenSigner $signer,
        private readonly string $httpEndpoint,
        private readonly ?string $region = null,
        private readonly string $defaultNamespace = 'default',
        private readonly int $subscribeTokenTtl = 3600,
        private readonly ?Closure $uidResolver = null,
        private readonly ?ClientInterface $client = null,
        private readonly mixed $credentialProvider = null,
        private readonly float $timeout = 2.0,
        private readonly float $connectTimeout = 1.0,
    ) {}

    /**
     * Authenticate a broadcasting auth request for a channel.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming auth request.
     * @return mixed The authentication response.
     *
     * @throws AccessDeniedHttpException When no channel is given or a guarded
     *                                   channel has no authenticated user.
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) ||
            ($this->isGuardedChannel($request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return $this->verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * Build the auth response: a per-channel subscribe token.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming auth request.
     * @param  mixed  $result  The channel callback result.
     * @return array{auth: string} The subscribe token under the `auth` key.
     *
     * @throws AccessDeniedHttpException When the request has no authenticated user.
     */
    public function validAuthenticationResponse($request, $result): array
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        $user = $this->retrieveUser($request, $channelName);

        if (! $user) {
            throw new AccessDeniedHttpException;
        }

        return [
            'auth' => $this->signer->subscribeToken(
                $this->resolveUid($user),
                $this->channelPath($request->channel_name),
                $this->subscribeTokenTtl,
            ),
        ];
    }

    /**
     * Publish an event to each given channel through the Event API.
     *
     * A `socket` entry in the payload (the sender's socket id, set by
     * {@see InteractsWithSockets} when `->toOthers()` is used) is
     * lifted out of `data` into a top-level `socket` envelope field;
     * AppSync has no server-side sender exclusion, so the Echo
     * connector drops events whose envelope socket matches its own id.
     *
     * @param  array<int, Channel>  $channels  The channels to broadcast to.
     * @param  string  $event  The broadcast event name.
     * @param  array<string, mixed>  $payload  The event payload.
     *
     * @throws BroadcastException When a publish call fails or is refused.
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        if ($channels === []) {
            return;
        }

        $socket = Arr::pull($payload, 'socket');
        $socket = is_scalar($socket) && (string) $socket !== '' ? (string) $socket : null;

        $client = $this->client();
        $signer = new SignatureV4('appsync', $this->resolveRegion());
        $credentials = null;

        /** @var Channel $channel */
        foreach ($channels as $channel) {
            $path = $this->channelPath($channel->name);

            try {
                $credentials ??= ($this->credentialsProvider())()->wait();

                $envelope = [
                    'event' => $event,
                    'channel' => $path,
                    'data' => $payload,
                ];

                if ($socket !== null) {
                    $envelope['socket'] = $socket;
                }

                $message = json_encode($envelope, JSON_THROW_ON_ERROR);

                $body = json_encode([
                    'channel' => $path,
                    'events' => [$message],
                ], JSON_THROW_ON_ERROR);

                $request = new Request('POST', $this->httpEndpoint, [
                    'Host' => $this->hostHeader(),
                    'Content-Type' => 'application/json',
                ], $body);

                $response = $client->send($signer->signRequest($request, $credentials));

                if ($response->getStatusCode() >= 300) {
                    throw new BroadcastException(sprintf(
                        'AppSync publish to channel %s failed with HTTP status %d.',
                        $path,
                        $response->getStatusCode(),
                    ));
                }
            } catch (BroadcastException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new BroadcastException(sprintf(
                    'AppSync publish to channel %s failed: %s',
                    $path,
                    $exception->getMessage(),
                ), 0, $exception);
            }
        }
    }

    /**
     * Map a Laravel channel name to the AppSync channel path this
     * broadcaster publishes to and issues tokens for.
     *
     * @param  string  $channelName  The channel name (prefixes included).
     * @return string The channel path `/{namespace}/{type}/{segment}`.
     */
    public function channelPath(string $channelName): string
    {
        return ChannelPath::from($channelName, $this->defaultNamespace);
    }

    /**
     * Resolve the `uid` claim for an authenticated user.
     *
     * @param  mixed  $user  The authenticated user model.
     * @return string The user identifier placed in subscribe tokens.
     */
    private function resolveUid(mixed $user): string
    {
        if ($this->uidResolver !== null) {
            return (string) ($this->uidResolver)($user);
        }

        return (string) $user->getAuthIdentifier();
    }

    /**
     * The HTTP client to publish with: the injected one, or a lazily
     * created default honouring the configured timeouts.
     *
     * @return ClientInterface The publish HTTP client.
     */
    private function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        return $this->resolvedClient ??= new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'http_errors' => false,
        ]);
    }

    /**
     * The AWS credentials provider to sign with: the injected callable or
     * credentials object, or a lazily created memoized default chain.
     *
     * @return callable A callable returning an AWS credentials promise.
     *
     * @throws InvalidArgumentException When the injected provider is unusable.
     */
    private function credentialsProvider(): callable
    {
        if ($this->credentialProvider !== null) {
            if ($this->credentialProvider instanceof CredentialsInterface) {
                $credentials = $this->credentialProvider;

                return static fn () => Create::promiseFor($credentials);
            }

            if (! is_callable($this->credentialProvider)) {
                throw new InvalidArgumentException(
                    'The credential provider must be a callable or an Aws\Credentials\CredentialsInterface.',
                );
            }

            return $this->credentialProvider;
        }

        return $this->resolvedCredentialProvider ??= CredentialProvider::memoize(
            CredentialProvider::defaultProvider(),
        );
    }

    /**
     * The SigV4 signing region: parsed from a real AppSync endpoint host,
     * else the configured region, else `us-east-1`.
     *
     * @return string The AWS region name.
     */
    private function resolveRegion(): string
    {
        $host = parse_url($this->httpEndpoint, PHP_URL_HOST);

        if (is_string($host) && preg_match('/appsync-api\.([a-z0-9-]+)\.amazonaws\.com$/', $host, $matches) === 1) {
            return $matches[1];
        }

        return $this->region ?? 'us-east-1';
    }

    /**
     * The value for the `Host` header, including a non-default port so
     * local endpoints such as `http://127.0.0.1:9400/event` sign and send
     * correctly.
     *
     * @return string The `host` or `host:port` authority.
     */
    private function hostHeader(): string
    {
        $host = (string) parse_url($this->httpEndpoint, PHP_URL_HOST);
        $port = parse_url($this->httpEndpoint, PHP_URL_PORT);
        $scheme = parse_url($this->httpEndpoint, PHP_URL_SCHEME) ?: 'https';

        $defaultPort = $scheme === 'http' ? 80 : 443;

        return $port !== null && $port !== $defaultPort ? "{$host}:{$port}" : $host;
    }
}
