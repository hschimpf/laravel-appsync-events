<?php

declare(strict_types=1);

namespace HDSSolutions\Laravel\AppSyncEvents;

use HDSSolutions\Laravel\AppSyncEvents\Auth\TokenSigner;
use HDSSolutions\Laravel\AppSyncEvents\Broadcasting\AppSyncBroadcaster;
use HDSSolutions\Laravel\AppSyncEvents\Console\InstallCommand;
use HDSSolutions\Laravel\AppSyncEvents\Console\SecretCommand;
use HDSSolutions\Laravel\AppSyncEvents\Exceptions\ConfigurationException;
use HDSSolutions\Laravel\AppSyncEvents\Http\ClientConfigController;
use HDSSolutions\Laravel\AppSyncEvents\Lambda\AppSyncAuthorizer;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the AWS AppSync Events broadcasting driver for Laravel.
 */
final class AppSyncEventsServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/appsync-events.php', 'appsync-events');

        $config = $this->app['config'];

        if ($config->get('broadcasting.connections.appsync') === null) {
            $config->set('broadcasting.connections.appsync', ['driver' => 'appsync']);
        }

        $this->registerBindings();
        $this->registerBroadcastDriver();
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/appsync-events.php' => config_path('appsync-events.php'),
        ], ['appsync-events-config', 'config']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SecretCommand::class,
                InstallCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $this->registerAuthRoute();
            $this->registerClientConfigRoute();
        });
    }

    /**
     * Bind the package services into the container.
     *
     * The signing secret is validated lazily (on resolution) so the
     * application can still boot — and run `appsync-events:install` —
     * before a secret is configured.
     */
    private function registerBindings(): void
    {
        $this->app->bind(TokenSigner::class, function (Container $app): TokenSigner {
            return new TokenSigner(
                $this->signingSecret($app),
                $app->make(ConfigRepository::class)->get('appsync-events.previous_signing_secrets'),
            );
        });

        $this->app->bind(AppSyncAuthorizer::class, function (Container $app): AppSyncAuthorizer {
            return new AppSyncAuthorizer(
                $this->signingSecret($app),
                (int) $app->make(ConfigRepository::class)->get('appsync-events.authorizer.cache_ttl', 300),
                $app->make(ConfigRepository::class)->get('appsync-events.previous_signing_secrets'),
            );
        });

        $this->app->singleton(AppSyncEventsManager::class);
    }

    /**
     * Register the `appsync` broadcasting driver.
     *
     * The driver factory merges the broadcasting connection definition
     * over the package config (`$connection + config('appsync-events')`),
     * so a connection may override `http_endpoint`, `signing_secret`,
     * `region`, `default_namespace` and the HTTP timeout settings. A
     * dedicated TokenSigner is built from the effective secret so
     * per-connection secrets are honoured.
     *
     * The factory is a first-class callable on purpose: since Laravel 13
     * `BroadcastManager::extend()` binds anonymous creator closures to
     * the manager instance (both `$this` and scope), which would break
     * calls to this provider's private methods.
     */
    private function registerBroadcastDriver(): void
    {
        $this->callAfterResolving(
            BroadcastFactory::class,
            fn (BroadcastFactory $broadcast) => $broadcast->extend(
                'appsync',
                $this->createBroadcaster(...),
            ),
        );
    }

    /**
     * Register the broadcasting authentication endpoint.
     *
     * Deferred to `Application::booted()` so routes registered by
     * application providers during their own `boot()` are already in
     * place. The route is registered through `Broadcast::routes()` —
     * Laravel's own mechanism (`BroadcastManager::routes`), which adds
     * GET and POST `/broadcasting/auth` without a route name and strips
     * CSRF protection. To never shadow an application-defined endpoint
     * we skip registration when:
     *  - `appsync-events.routes.auth.enabled` is false;
     *  - routes are cached (`BroadcastManager::routes` would no-op anyway);
     *  - a route named `broadcasting.auth` already exists;
     *  - any route already claims the `/broadcasting/auth` URI —
     *    applications may register `Broadcast::routes()` with custom
     *    middleware, and that definition must win.
     *
     * Should the application register `Broadcast::routes()` even later
     * (e.g. from its own `booted()` callback), Laravel's route collection
     * keys compiled routes by method+URI, so the later registration
     * replaces ours at match time — the application's endpoint still
     * wins either ordering.
     */
    private function registerAuthRoute(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if (! $config->get('appsync-events.routes.auth.enabled', true)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        if (Route::has('broadcasting.auth')) {
            return;
        }

        foreach ($this->app->make('router')->getRoutes()->getRoutes() as $route) {
            /** @var \Illuminate\Routing\Route $route */
            if ($route->uri() === 'broadcasting/auth') {
                return;
            }
        }

        Broadcast::routes([
            'middleware' => $config->get('appsync-events.routes.auth.middleware', ['web']),
        ]);
    }

    /**
     * Register the opt-in client configuration endpoint.
     *
     * Disabled by default (`appsync-events.routes.client_config.enabled`);
     * when enabled it exposes `GET {uri}` under the configured middleware,
     * returning the {@see AppSyncEventsManager::clientConfig()} payload.
     */
    private function registerClientConfigRoute(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if (! $config->get('appsync-events.routes.client_config.enabled', false)) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::middleware((array) $config->get('appsync-events.routes.client_config.middleware', ['web', 'auth']))
            ->get(
                (string) $config->get('appsync-events.routes.client_config.uri', 'broadcasting/appsync'),
                ClientConfigController::class,
            )
            ->name('appsync-events.client-config');
    }

    /**
     * Create the broadcaster for a broadcasting connection.
     *
     * @param  Container  $app  The application container.
     * @param  array<string, mixed>  $connection  The broadcasting connection definition.
     *
     * @throws ConfigurationException When the effective `http_endpoint`
     *                                or `signing_secret` is missing.
     */
    private function createBroadcaster(Container $app, array $connection): AppSyncBroadcaster
    {
        /** @var array<string, mixed> $settings */
        $settings = $connection + (array) $app->make(ConfigRepository::class)->get('appsync-events', []);

        $httpEndpoint = $settings['http_endpoint'] ?? null;

        if (! is_string($httpEndpoint) || $httpEndpoint === '') {
            throw ConfigurationException::missing(
                'The AppSync HTTP endpoint',
                'appsync-events.http_endpoint',
                'APPSYNC_HTTP_ENDPOINT',
            );
        }

        $secret = $settings['signing_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw ConfigurationException::missing(
                'The AppSync channel signing secret',
                'appsync-events.signing_secret',
                'APPSYNC_CHANNEL_SIGNING_SECRET',
            );
        }

        // A connection that defines its own `signing_secret` must also
        // define its own `previous_signing_secrets` — the package-level
        // previous list belongs to the package-level primary secret.
        $previousSecrets = array_key_exists('signing_secret', $connection)
            ? ($connection['previous_signing_secrets'] ?? null)
            : ($settings['previous_signing_secrets'] ?? null);

        return new AppSyncBroadcaster(
            signer: new TokenSigner($secret, $previousSecrets),
            httpEndpoint: $httpEndpoint,
            region: is_string($settings['region'] ?? null) ? $settings['region'] : null,
            defaultNamespace: is_string($settings['default_namespace'] ?? null) ? $settings['default_namespace'] : 'default',
            subscribeTokenTtl: (int) ($settings['tokens']['subscribe_ttl'] ?? 3600),
            uidResolver: fn (object $user): string => $app->make(AppSyncEventsManager::class)->uid($user),
            timeout: (float) ($settings['http']['timeout'] ?? 2.0),
            connectTimeout: (float) ($settings['http']['connect_timeout'] ?? 1.0),
        );
    }

    /**
     * Resolve the configured signing secret, ensuring it is present.
     *
     * @param  Container  $app  The application container.
     *
     * @throws ConfigurationException When `APPSYNC_CHANNEL_SIGNING_SECRET`
     *                                / `appsync-events.signing_secret` is missing.
     */
    private function signingSecret(Container $app): string
    {
        $secret = $app->make(ConfigRepository::class)->get('appsync-events.signing_secret');

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
