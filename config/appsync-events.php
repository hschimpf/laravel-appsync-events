<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Event API endpoints
    |--------------------------------------------------------------------------
    |
    | The HTTP endpoint is used by the server-side broadcaster to publish
    | events (`POST {http_endpoint}`), and its authority is embedded in the
    | client configuration for subscribe authorization. The realtime
    | endpoint is handed to the JS connector for the WebSocket connection.
    |
    | AWS format:
    |   http:     https://<id>.appsync-api.<region>.amazonaws.com/event
    |   realtime: wss://<id>.appsync-realtime-api.<region>.amazonaws.com/event/realtime
    |
    */

    'http_endpoint' => env('APPSYNC_HTTP_ENDPOINT'),

    'realtime_endpoint' => env('APPSYNC_REALTIME_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Channel token signing secret
    |--------------------------------------------------------------------------
    |
    | HMAC secret shared between this application (which issues connect and
    | subscribe tokens) and the AppSync Events Lambda authorizer (which
    | verifies them). Generate one with `appsync-events:secret`. Required.
    |
    */

    'signing_secret' => env('APPSYNC_CHANNEL_SIGNING_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | AWS region
    |--------------------------------------------------------------------------
    |
    | Used for SigV4 signing when the region cannot be parsed from the
    | `http_endpoint` host. Falls back to `us-east-1` when neither is set.
    |
    */

    'region' => env('APPSYNC_REGION', env('AWS_DEFAULT_REGION')),

    /*
    |--------------------------------------------------------------------------
    | Default channel namespace
    |--------------------------------------------------------------------------
    |
    | The AppSync channel namespace used for channel names that carry no
    | `.`-separated namespace part (e.g. `orders` maps to
    | `/{default_namespace}/public/orders`).
    |
    */

    'default_namespace' => env('APPSYNC_DEFAULT_NAMESPACE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Token lifetimes
    |--------------------------------------------------------------------------
    |
    | Seconds before issued tokens expire. Connect tokens are long-lived
    | (the WebSocket handshake credential); subscribe tokens are short and
    | issued per channel by the broadcasting auth endpoint.
    |
    */

    'tokens' => [
        'connect_ttl' => 43200,
        'subscribe_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lambda authorizer
    |--------------------------------------------------------------------------
    |
    | `cache_ttl` is the `ttlOverride` returned by the authorizer for
    | authorized connections. See Lambda\AppSyncAuthorizer.
    |
    */

    'authorizer' => [
        'cache_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publish HTTP client
    |--------------------------------------------------------------------------
    |
    | Timeouts (seconds) applied to SigV4-signed publish requests against
    | the Event API HTTP endpoint.
    |
    */

    'http' => [
        'timeout' => 2.0,
        'connect_timeout' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | User id resolver
    |--------------------------------------------------------------------------
    |
    | How the `uid` claim placed in tokens is derived from the
    | authenticated user. `null` uses `getAuthIdentifier()`; or set an
    | invokable class-string resolved from the container (a Closure cannot
    | live in a cached config — use AppSyncEvents::resolveUidUsing() at
    | runtime instead).
    |
    */

    'uid_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | JS connector settings
    |--------------------------------------------------------------------------
    |
    | Values shipped to clients by AppSyncEvents::clientConfig() /
    | AppSyncEventsManager::clientConfig(). `auth_endpoint` is where Echo
    | requests channel authorization; `inactivity_timeout` is the
    | connector-side reconnect hint in seconds.
    |
    */

    'client' => [
        'auth_endpoint' => '/broadcasting/auth',
        'inactivity_timeout' => env('APPSYNC_INACTIVITY_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | `auth`: registers the broadcasting authentication endpoint via
    | Broadcast::routes() (never replaces an application-defined route).
    | `client_config`: opt-in JSON endpoint serving the connector
    | configuration for decoupled frontends; choose its URI and middleware
    | (defaults to `web` + `auth`).
    |
    */

    'routes' => [
        'auth' => [
            'enabled' => true,
            'middleware' => ['web'],
        ],
        'client_config' => [
            'enabled' => false,
            'uri' => 'broadcasting/appsync',
            'middleware' => ['web', 'auth'],
        ],
    ],

];
