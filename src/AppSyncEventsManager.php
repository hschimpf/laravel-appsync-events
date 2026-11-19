<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents;

use Closure;
use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Exceptions\ConfigurationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Central access point for the package: resolves the uid claim placed in
 * issued tokens, signs tokens through the configured signer, and builds
 * the client configuration consumed by the JS connector.
 */
final class AppSyncEventsManager
{
    /**
     * The runtime uid resolver registered via {@see resolveUidUsing()}.
     */
    private ?Closure $uidResolver = null;

    /**
     * The lazily built token signer.
     */
    private ?TokenSigner $signer = null;

    /**
     * Create a new manager instance.
     *
     * @param  Container  $container  The application container.
     * @param  ConfigRepository  $config  The configuration repository.
     * @param  AuthFactory  $auth  The auth factory, used to find the current user.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Replace the uid resolution strategy at runtime.
     *
     * The closure receives the authenticated user object and must return
     * the uid to embed in token claims. Takes precedence over the
     * `appsync-events.uid_resolver` config value.
     *
     * @param  Closure  $resolver  `fn (object $user): string`
     * @return $this
     */
    public function resolveUidUsing(Closure $resolver): static
    {
        $this->uidResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the uid claim for the given user.
     *
     * Precedence:
     *  1. The closure set via {@see resolveUidUsing()}.
     *  2. An invokable class-string from `appsync-events.uid_resolver`,
     *     resolved through the container.
     *  3. `(string) $user->getAuthIdentifier()`.
     *
     * @param  object  $user  The user to resolve a uid for — normally an
     *                        {@see Authenticatable} implementation.
     * @return string The uid to embed in token claims.
     *
     * @throws InvalidArgumentException When the user cannot provide an identifier.
     */
    public function uid(object $user): string
    {
        if ($this->uidResolver instanceof Closure) {
            return (string) ($this->uidResolver)($user);
        }

        $resolverClass = $this->config->get('appsync-events.uid_resolver');

        if (is_string($resolverClass) && $resolverClass !== '') {
            $resolver = $this->container->make($resolverClass);

            return (string) $resolver($user);
        }

        if (method_exists($user, 'getAuthIdentifier')) {
            return (string) $user->getAuthIdentifier();
        }

        throw new InvalidArgumentException(sprintf(
            'Unable to resolve a uid for [%s]: it does not implement getAuthIdentifier() and no uid resolver is configured.',
            $user::class,
        ));
    }

    /**
     * Build the client configuration consumed by the JS connector.
     *
     * The returned array uses camelCase keys matching the
     * `laravel-echo-appsync` connector options: `wsEndpoint`, `httpHost`,
     * `connectToken`, `authEndpoint`, `defaultNamespace` and
     * `inactivityTimeout`. Returns `null` for guests.
     *
     * @param  Authenticatable|null  $user  The user to configure; defaults
     *                                      to the current user of the default guard.
     * @return array{wsEndpoint: string, httpHost: string, connectToken: string,
     *     authEndpoint: string, defaultNamespace: string, inactivityTimeout: int}|null
     *
     * @throws ConfigurationException When the realtime endpoint, HTTP
     *                                endpoint or signing secret is missing.
     */
    public function clientConfig(?Authenticatable $user = null): ?array
    {
        $user ??= $this->auth->guard()->user();

        if ($user === null) {
            return null;
        }

        $realtime = $this->config->get('appsync-events.realtime_endpoint');

        if (! is_string($realtime) || $realtime === '') {
            throw ConfigurationException::missing(
                'The AppSync realtime endpoint',
                'appsync-events.realtime_endpoint',
                'APPSYNC_REALTIME_ENDPOINT',
            );
        }

        return [
            'wsEndpoint' => $realtime,
            'httpHost' => $this->httpHost($this->httpEndpoint()),
            'connectToken' => $this->signer()->connectToken(
                $this->uid($user),
                (int) $this->config->get('appsync-events.tokens.connect_ttl', 43200),
            ),
            'authEndpoint' => $this->config->get('appsync-events.client.auth_endpoint', '/broadcasting/auth'),
            'defaultNamespace' => $this->config->get('appsync-events.default_namespace', 'default'),
            'inactivityTimeout' => (int) $this->config->get('appsync-events.client.inactivity_timeout', 10),
        ];
    }

    /**
     * The token signer built from the configured signing secret, also
     * accepting `previous_signing_secrets` for verification.
     *
     * @throws ConfigurationException When `appsync-events.signing_secret`
     *                                / `APPSYNC_CHANNEL_SIGNING_SECRET` is missing.
     */
    public function signer(): TokenSigner
    {
        return $this->signer ??= new TokenSigner(
            $this->signingSecret(),
            $this->config->get('appsync-events.previous_signing_secrets'),
        );
    }

    /**
     * Extract the `host[:port]` authority from an AppSync endpoint URL.
     *
     * For example `https://abc123.appsync-api.eu-west-1.amazonaws.com/event`
     * yields `abc123.appsync-api.eu-west-1.amazonaws.com` and
     * `http://127.0.0.1:9401/event` yields `127.0.0.1:9401`.
     *
     * @param  string  $endpoint  The endpoint URL to parse.
     * @return string The `host` or `host:port` authority.
     *
     * @throws ConfigurationException When the endpoint is not a valid URL.
     */
    public function httpHost(string $endpoint): string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new ConfigurationException(sprintf(
                'The configured endpoint [%s] is not a valid URL. Check the appsync-events.http_endpoint config value.',
                $endpoint,
            ));
        }

        $port = parse_url($endpoint, PHP_URL_PORT);

        return is_int($port) ? sprintf('%s:%d', $host, $port) : $host;
    }

    /**
     * Resolve the configured HTTP endpoint, ensuring it is present.
     *
     * @throws ConfigurationException When `APPSYNC_HTTP_ENDPOINT` is missing.
     */
    private function httpEndpoint(): string
    {
        $endpoint = $this->config->get('appsync-events.http_endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            throw ConfigurationException::missing(
                'The AppSync HTTP endpoint',
                'appsync-events.http_endpoint',
                'APPSYNC_HTTP_ENDPOINT',
            );
        }

        return $endpoint;
    }

    /**
     * Resolve the configured signing secret, ensuring it is present.
     *
     * @throws ConfigurationException When `APPSYNC_CHANNEL_SIGNING_SECRET`
     *                                / `appsync-events.signing_secret` is missing.
     */
    private function signingSecret(): string
    {
        $secret = $this->config->get('appsync-events.signing_secret');

        if (! is_string($secret) || $secret === '') {
            throw ConfigurationException::missing(
                'The AppSync channel signing secret',
                'appsync-events.signing_secret',
                'APPSYNC_CHANNEL_SIGNING_SECRET',
            );
        }

        return $secret;
    }
}
