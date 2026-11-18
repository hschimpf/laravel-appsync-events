import type { EchoOptions } from 'laravel-echo';

import { AppSyncConnector } from './connector.js';

/**
 * The minimal WebSocket surface the connector needs. Both the DOM
 * `WebSocket` and compatible test doubles satisfy this interface.
 */
export interface AppSyncWebSocketLike {
    /** The connection state; compare with the `WS_*` constants in {@link protocol}. */
    readyState: number;
    onopen: ((event: unknown) => void) | null;
    onmessage: ((event: { data: unknown }) => void) | null;
    onclose: ((event: unknown) => void) | null;
    onerror: ((event: unknown) => void) | null;
    /** Send a text frame. */
    send(data: string): void;
    /** Close the socket. */
    close(code?: number, reason?: string): void;
}

/**
 * A WebSocket implementation constructor, e.g. the global `WebSocket` or
 * a test double.
 */
export type AppSyncWebSocketConstructor = new (
    url: string,
    protocols?: string | string[],
) => AppSyncWebSocketLike;

/**
 * Options accepted by {@link AppSyncConnector} (and by
 * {@link appSyncEchoOptions}). Unknown keys are passed through to the
 * Echo base connector.
 *
 * @example
 * ```ts
 * const echo = new Echo<'function'>(appSyncEchoOptions({
 *     wsEndpoint: 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime',
 *     httpHost: 'abc123.appsync-api.eu-west-1.amazonaws.com',
 *     connectToken: () => fetch('/broadcasting/appsync').then((r) => r.json()).then((c) => c.connectToken),
 * }));
 * ```
 */
export interface AppSyncConnectorOptions {
    /**
     * The AppSync Events realtime endpoint,
     * `wss://<id>.appsync-realtime-api.<region>.amazonaws.com/event/realtime`.
     * `ws://` is allowed for local endpoints and emulators.
     */
    wsEndpoint: string;

    /**
     * The connect token sent as `Authorization` in the handshake header.
     * Pass a function to have it re-evaluated on EVERY (re)connect so
     * expired tokens can be refreshed; it may be async. When omitted and
     * {@link publicApiKey} is set, the API key is sent instead; when
     * neither is configured, the handshake carries only `host`.
     */
    connectToken?: string | (() => string | Promise<string>);

    /**
     * The `host` value embedded in handshake and subscribe authorization
     * objects (`host[:port]` of the HTTP endpoint). Defaults to the host
     * of {@link wsEndpoint} with `appsync-realtime-api` replaced by
     * `appsync-api`.
     */
    httpHost?: string;

    /**
     * The endpoint requested for channel authorization. Defaults to
     * `/broadcasting/auth`.
     */
    authEndpoint?: string;

    /**
     * Extra headers sent with the authorization request (e.g. CSRF).
     */
    auth?: { headers: Record<string, string> };

    /**
     * A bearer token added as `Authorization: Bearer <token>` on the
     * authorization request — useful for decoupled frontends without
     * session cookies. May be a function, re-evaluated per request.
     */
    bearerToken?: string | (() => string | Promise<string>);

    /**
     * The `credentials` mode of the authorization `fetch` request
     * (e.g. `'include'` to send cross-origin cookies).
     */
    credentials?: RequestCredentials;

    /**
     * Replaces the built-in authorization request entirely. Receives the
     * Laravel channel name and the socket id; must resolve the subscribe
     * token.
     */
    authorizer?: (channelName: string, socketId: string) => Promise<string>;

    /**
     * An `x-api-key` credential used for the connection handshake (when
     * no {@link connectToken} is configured) and for subscribing to
     * public channels.
     */
    publicApiKey?: string;

    /**
     * The AppSync namespace used for channel names without a
     * `.`-separated namespace part. Defaults to `'default'`.
     */
    defaultNamespace?: string;

    /**
     * The Echo event namespace (like other connectors). Defaults to
     * `'App.Events'`; pass `false` to disable namespacing.
     */
    namespace?: string | false;

    /**
     * Log protocol activity to the console. Defaults to `false`.
     */
    debug?: boolean;

    /**
     * The WebSocket implementation — injectable for tests and
     * non-browser runtimes. Defaults to `globalThis.WebSocket`.
     */
    WebSocket?: AppSyncWebSocketConstructor;

    /**
     * The fetch implementation for authorization requests. Defaults to
     * `globalThis.fetch`.
     */
    fetch?: typeof fetch;

    /**
     * Reconnect backoff tuning: `baseMs` (default 1000) is doubled per
     * attempt up to `capMs` (default 30000), then jittered by 0.5–1.0×.
     */
    reconnect?: { baseMs?: number; capMs?: number };

    /**
     * Additional Echo options are passed through untouched.
     */
    [key: string]: unknown;
}

/**
 * The options bag the connector actually runs with: every documented
 * default resolved by `setOptions()`.
 *
 * @internal
 */
export type ResolvedAppSyncConnectorOptions = AppSyncConnectorOptions & {
    authEndpoint: string;
    defaultNamespace: string;
    debug: boolean;
    WebSocket: AppSyncWebSocketConstructor;
    fetch: typeof fetch;
    reconnect: { baseMs: number; capMs: number };
};

/**
 * Build an options object usable as `new Echo(appSyncEchoOptions({…}))`
 * — or, where stricter inference requires it,
 * `new Echo<'function'>(appSyncEchoOptions({…}))`.
 *
 * @param options - The connector options; `broadcaster` is set to
 *     {@link AppSyncConnector} for you.
 * @returns Echo options whose `broadcaster` is the AppSync connector.
 */
export function appSyncEchoOptions(
    options: AppSyncConnectorOptions,
): EchoOptions<'function'> & { broadcaster: typeof AppSyncConnector } {
    // `EchoOptions` types bearerToken as `string | null`; our function
    // variant is resolved by the connector itself, so the cast stays
    // contained here instead of leaking into userland.
    return {
        ...options,
        broadcaster: AppSyncConnector,
    } as EchoOptions<'function'> & { broadcaster: typeof AppSyncConnector };
}
