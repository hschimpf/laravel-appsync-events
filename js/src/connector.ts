import { Connector, type ConnectionStatus, type EchoOptions, type PresenceChannel } from 'laravel-echo';

import { AppSyncChannel } from './channel.js';
import { toAppSyncChannel } from './channelPath.js';
import type { AppSyncConnectorOptions, AppSyncWebSocketLike, ResolvedAppSyncConnectorOptions } from './options.js';
import {
    APPSYNC_WS_SUBPROTOCOL,
    WS_CLOSED,
    WS_CONNECTING,
    WS_OPEN,
    type AuthErrorEvent,
    type ConnectionAckMessage,
    type DataMessage,
    type InboundMessage,
    type OutboundFrame,
    type SubscribeFrame,
} from './protocol.js';

const LOG_PREFIX = '[AppSyncConnector]';

/**
 * The default keep-alive window when `connection_ack` carries no
 * `connectionTimeoutMs`.
 */
const DEFAULT_CONNECTION_TIMEOUT_MS = 300_000;

/**
 * Encode a string as unpadded base64url.
 *
 * @param input - The UTF-8 string to encode.
 */
function base64UrlEncode(input: string): string {
    const base64 = typeof btoa === 'function'
        ? btoa(String.fromCharCode(...new TextEncoder().encode(input)))
        : Buffer.from(input, 'utf8').toString('base64');

    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Resolve an option that may be a value or a (possibly async) factory.
 *
 * @param value - The value or factory to resolve.
 */
async function resolveOption<T>(value: T | (() => T | Promise<T>) | undefined): Promise<T | undefined> {
    return typeof value === 'function' ? (value as () => T | Promise<T>)() : value;
}

/**
 * A Laravel Echo connector for AWS AppSync Events.
 *
 * Handles the `aws-appsync-event-ws` handshake (`header-<base64url(json)>`
 * subprotocol carrying `host` plus `Authorization` or `x-api-key`),
 * `connection_init`/`connection_ack`, per-channel `subscribe` frames with
 * tokens fetched from the broadcasting auth endpoint, `ka` keep-alives,
 * and jittered exponential-backoff reconnects.
 *
 * @example
 * ```ts
 * import Echo from 'laravel-echo';
 * import { appSyncEchoOptions } from '@hds-solutions/laravel-echo-appsync';
 *
 * const echo = new Echo<'function'>(appSyncEchoOptions({
 *     wsEndpoint: 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime',
 *     connectToken: async () => (await (await fetch('/broadcasting/appsync')).json()).connectToken,
 * }));
 *
 * echo.private('orders.1').listen('.OrderShipped', (payload) => console.log(payload));
 * ```
 */
export class AppSyncConnector extends Connector<any, AppSyncChannel, AppSyncChannel, PresenceChannel> {
    /**
     * The underlying socket, when a connection attempt is in progress or
     * established.
     */
    protected declare socket: AppSyncWebSocketLike | null;

    /**
     * Channels created through {@link channel}, keyed by wire name.
     */
    protected declare channels: Record<string, AppSyncChannel>;

    /**
     * Live subscriptions, keyed by subscription id.
     */
    protected declare subscriptions: Map<string, AppSyncChannel>;

    /**
     * Channels waiting to be subscribed once connected.
     */
    protected declare pendingChannels: AppSyncChannel[];

    /**
     * The stable socket id reported to the auth endpoint.
     */
    protected declare stableSocketId: string;

    /**
     * Whether `connection_ack` was received on the current socket.
     */
    protected declare connected: boolean;

    /**
     * Set by {@link disconnect} to suppress reconnect handling.
     */
    protected declare closing: boolean;

    /**
     * The keep-alive window reported by `connection_ack`.
     */
    protected declare connectionTimeoutMs: number;

    /**
     * The pending keep-alive timeout.
     */
    protected declare keepAliveTimer: ReturnType<typeof setTimeout> | null;

    /**
     * The pending reconnect timeout.
     */
    protected declare reconnectTimer: ReturnType<typeof setTimeout> | null;

    /**
     * The number of consecutive reconnect attempts.
     */
    protected declare reconnectAttempts: number;

    /**
     * Bumped on every connect()/disconnect() so stale async work
     * (pending connect tokens, in-flight authorizations) can be
     * discarded.
     */
    protected declare connectGeneration: number;

    /**
     * Registered connection-status listeners.
     */
    protected declare connectionListeners: Array<(status: ConnectionStatus) => void>;

    /**
     * Base `Connector` calls `setOptions()` and then `connect()` from its
     * constructor. Class field initializers would run after `super()` and
     * wipe the socket `connect()` just created, so all mutable state is
     * initialized here instead.
     *
     * @param options - The raw connector options.
     */
    protected setOptions(options: EchoOptions<any>): void {
        super.setOptions(options);

        const resolved = this.opts;
        resolved.defaultNamespace ??= 'default';
        resolved.debug ??= false;
        resolved.WebSocket ??= globalThis.WebSocket as unknown as ResolvedAppSyncConnectorOptions['WebSocket'];
        // A browser's fetch is a WebIDL operation that throws an "Illegal
        // invocation" TypeError unless its receiver is Window/undefined,
        // so it must not be stored as a method call target; the indirection
        // also resolves the global lazily for runtimes that stub it late.
        resolved.fetch ??= (input, init) => globalThis.fetch(input, init);
        resolved.reconnect = {
            baseMs: resolved.reconnect?.baseMs ?? 1_000,
            capMs: resolved.reconnect?.capMs ?? 30_000,
        };

        this.socket = null;
        this.channels = {};
        this.subscriptions = new Map();
        this.pendingChannels = [];
        this.stableSocketId = crypto.randomUUID();
        this.connected = false;
        this.closing = false;
        this.connectionTimeoutMs = DEFAULT_CONNECTION_TIMEOUT_MS;
        this.keepAliveTimer = null;
        this.reconnectTimer = null;
        this.reconnectAttempts = 0;
        this.connectGeneration = 0;
        this.connectionListeners = [];
    }

    /**
     * The connector options with all defaults resolved.
     */
    protected get opts(): ResolvedAppSyncConnectorOptions {
        return this.options as unknown as ResolvedAppSyncConnectorOptions;
    }

    /**
     * Create a fresh connection. Idempotent: a live or in-flight socket
     * is left alone. The connect token — when configured — is resolved
     * before the socket is opened, so a function token is re-evaluated
     * on every (re)connect.
     */
    public connect(): void {
        if (this.socket !== null && this.socket.readyState !== WS_CLOSED) {
            return;
        }

        this.closing = false;
        const generation = ++this.connectGeneration;

        void resolveOption(this.opts.connectToken).then(
            (token) => {
                if (generation !== this.connectGeneration || this.closing) {
                    return;
                }

                this.notifyConnectionChange('connecting');
                this.openSocket(token);
            },
            (error: unknown) => {
                if (generation !== this.connectGeneration || this.closing) {
                    return;
                }

                this.debug('connect token resolution failed', error);
                this.scheduleReconnect();
            },
        );
    }

    /**
     * Disconnect from the socket. Timers and pending reconnects are
     * stopped; live subscriptions are re-queued so a later
     * {@link connect} re-subscribes with fresh tokens.
     */
    public disconnect(): void {
        this.closing = true;
        this.connected = false;
        this.connectGeneration++;
        this.clearKeepAlive();

        if (this.reconnectTimer !== null) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }

        this.socket?.close();
        this.socket = null;

        this.requeueSubscriptions();
        this.notifyConnectionChange('disconnected');
    }

    /**
     * Get a channel instance by name.
     *
     * @param channel - The (public) channel name.
     */
    public channel(channel: string): AppSyncChannel {
        this.channels[channel] ??= new AppSyncChannel(this, channel, this.options);

        return this.channels[channel];
    }

    /**
     * Get a private channel instance by name.
     *
     * @param channel - The channel name without the `private-` prefix.
     */
    public privateChannel(channel: string): AppSyncChannel {
        return this.channel(`private-${channel}`);
    }

    /**
     * Get a presence channel instance by name.
     *
     * @param channel - The channel name.
     * @throws Always — presence channels are not supported yet.
     */
    public presenceChannel(channel: string): PresenceChannel {
        throw new Error('Presence channels are not supported by the AppSync connector yet');
    }

    /**
     * Listen for an event on a channel instance.
     *
     * @param name - The channel name.
     * @param event - The event name.
     * @param callback - Invoked with the event payload.
     */
    public listen(name: string, event: string, callback: CallableFunction): AppSyncChannel {
        return this.channel(name).listen(event, callback);
    }

    /**
     * Leave the given channel, as well as its private and presence
     * variants.
     *
     * @param channel - The channel name without any type prefix.
     */
    public leave(channel: string): void {
        for (const prefix of ['', 'private-', 'presence-']) {
            this.leaveChannel(prefix + channel);
        }
    }

    /**
     * Leave the given channel.
     *
     * @param name - The exact channel name, including any prefix.
     */
    public leaveChannel(name: string): void {
        this.channels[name]?.unsubscribe();
    }

    /**
     * Get the socket id of the connection — stable for the lifetime of
     * this connector instance.
     */
    public socketId(): string {
        return this.stableSocketId;
    }

    /**
     * Get the current connection status.
     */
    public connectionStatus(): ConnectionStatus {
        if (this.socket !== null && this.socket.readyState === WS_CONNECTING) {
            return 'connecting';
        }

        if (this.socket !== null && this.socket.readyState === WS_OPEN && !this.connected) {
            return 'connecting';
        }

        if (this.connected) {
            return 'connected';
        }

        return this.reconnectTimer !== null ? 'reconnecting' : 'disconnected';
    }

    /**
     * Subscribe to connection status changes.
     *
     * @param callback - Invoked with each status transition.
     * @returns A function that unregisters the callback.
     */
    public onConnectionChange(callback: (status: ConnectionStatus) => void): () => void {
        this.connectionListeners.push(callback);

        return () => {
            this.connectionListeners = this.connectionListeners.filter((listener) => listener !== callback);
        };
    }

    /**
     * Queue a channel for subscription and flush when connected.
     *
     * @param channel - The channel to subscribe.
     */
    public subscribe(channel: AppSyncChannel): void {
        if (this.pendingChannels.includes(channel) || this.subscriptions.has(channel.subscriptionId)) {
            return;
        }

        this.pendingChannels.push(channel);

        if (this.socket === null) {
            this.connect();
        }

        this.flushPendingChannels();
    }

    /**
     * Unsubscribe a channel: send the frame when the socket is open and
     * remove it from all maps.
     *
     * @param channel - The channel to unsubscribe.
     */
    public unsubscribe(channel: AppSyncChannel): void {
        this.pendingChannels = this.pendingChannels.filter((pending) => pending !== channel);

        if (this.subscriptions.has(channel.subscriptionId)) {
            this.send({ type: 'unsubscribe', id: channel.subscriptionId });
            this.subscriptions.delete(channel.subscriptionId);
        }

        delete this.channels[channel.name];
    }

    /**
     * Open the socket with the handshake subprotocols.
     *
     * @param connectToken - The resolved connect token, when configured.
     */
    protected openSocket(connectToken?: string): void {
        const opts = this.opts;
        const authorization: Record<string, string> = { host: this.httpHost() };

        if (connectToken) {
            authorization.Authorization = connectToken;
        } else if (opts.publicApiKey) {
            authorization['x-api-key'] = opts.publicApiKey;
        }

        const header = base64UrlEncode(JSON.stringify(authorization));
        const WebSocketImpl = opts.WebSocket;
        const socket = new WebSocketImpl(opts.wsEndpoint, [APPSYNC_WS_SUBPROTOCOL, `header-${header}`]);

        this.socket = socket;

        socket.onopen = () => {
            this.send({ type: 'connection_init' });
        };

        socket.onmessage = (event: { data: unknown }) => {
            this.handleMessageData(event.data);
        };

        socket.onclose = () => this.handleClose();
        socket.onerror = () => this.handleClose();
    }

    /**
     * Parse and dispatch one incoming text frame.
     *
     * @param data - The raw frame payload.
     */
    protected handleMessageData(data: unknown): void {
        try {
            this.handleMessage(JSON.parse(data as string) as InboundMessage);
        } catch (error) {
            this.debug('invalid message frame', data, error);
        }
    }

    /**
     * Dispatch one parsed protocol message.
     *
     * @param message - The parsed frame.
     */
    protected handleMessage(message: InboundMessage): void {
        switch (message.type) {
            case 'connection_ack':
                this.connectionTimeoutMs = (message as ConnectionAckMessage).connectionTimeoutMs ?? DEFAULT_CONNECTION_TIMEOUT_MS;
                this.connected = true;
                this.reconnectAttempts = 0;
                this.notifyConnectionChange('connected');
                this.flushPendingChannels();
                this.resetKeepAliveTimer();
                break;

            case 'subscribe_success':
                this.subscriptions.get(message.id as string)?.notifySubscribed();
                break;

            case 'subscribe_error':
                this.subscriptions.get(message.id as string)?.notifyError(message);
                break;

            case 'data':
                this.handleDataMessage(message as DataMessage);
                break;

            case 'connection_error':
                this.debug('connection_error', message);
                this.handleClose();
                break;

            case 'ka':
                this.resetKeepAliveTimer();
                break;

            case 'unsubscribe_success':
            case 'unsubscribe_error':
            case 'broadcast_error':
                this.debug(message.type, message);
                break;

            default:
                this.debug('unknown message', message);
        }
    }

    /**
     * Dispatch a `data` frame: `event` may be a JSON string or an array
     * of JSON strings of `{event, data}`; malformed items are ignored.
     *
     * @param message - The parsed `data` frame.
     */
    protected handleDataMessage(message: DataMessage): void {
        const events = Array.isArray(message.event) ? message.event : [message.event];

        for (const item of events) {
            try {
                const payload = JSON.parse(item as string) as { event: string; data: unknown };
                this.subscriptions.get(message.id)?.emit(payload.event, payload.data);
            } catch (error) {
                this.debug('invalid event payload', item, error);
            }
        }
    }

    /**
     * Handle a socket close/error: re-queue live subscriptions and
     * schedule a reconnect unless disconnect() was requested.
     */
    protected handleClose(): void {
        if (this.closing || this.reconnectTimer !== null) {
            return;
        }

        this.connected = false;
        this.clearKeepAlive();

        this.socket?.close();
        this.socket = null;

        this.requeueSubscriptions();
        this.notifyConnectionChange('disconnected');
        this.scheduleReconnect();
    }

    /**
     * Schedule the next reconnect attempt with jittered exponential
     * backoff (`baseMs` doubled per attempt up to `capMs`, jittered by
     * 0.5–1.0×).
     */
    protected scheduleReconnect(): void {
        const { baseMs, capMs } = this.opts.reconnect;
        const delay = Math.min(capMs, baseMs * 2 ** this.reconnectAttempts);
        const jitter = delay * (0.5 + Math.random() * 0.5);

        this.reconnectAttempts++;
        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;
            this.connect();
        }, jitter);
        this.notifyConnectionChange('reconnecting');
    }

    /**
     * Send pending subscriptions once connected.
     */
    protected flushPendingChannels(): void {
        if (!this.connected) {
            return;
        }

        while (this.pendingChannels.length > 0) {
            const channel = this.pendingChannels.shift();
            if (channel) {
                void this.doSubscribe(channel);
            }
        }
    }

    /**
     * Authorize (when needed) and send the `subscribe` frame for one
     * channel.
     *
     * @param channel - The channel to subscribe.
     */
    protected async doSubscribe(channel: AppSyncChannel): Promise<void> {
        const generation = this.connectGeneration;
        const opts = this.opts;
        const authorization: Record<string, string> = { host: this.httpHost() };

        // Track the channel early so handleClose() can re-queue it if the
        // connection drops while its authorization is in flight.
        this.subscriptions.set(channel.subscriptionId, channel);

        if (channel.name.startsWith('private-') || channel.name.startsWith('presence-')) {
            try {
                authorization.Authorization = await this.authorizeChannel(channel.name);
            } catch (error) {
                this.debug('authorization failed', channel.name, error);
                this.subscriptions.delete(channel.subscriptionId);
                channel.notifyError({
                    type: 'auth_error',
                    status: (error as { status?: number }).status,
                    error,
                } satisfies AuthErrorEvent);

                return;
            }
        } else if (opts.publicApiKey) {
            authorization['x-api-key'] = opts.publicApiKey;
        }

        if (this.subscriptions.get(channel.subscriptionId) !== channel || generation !== this.connectGeneration) {
            return;
        }

        const frame: SubscribeFrame = {
            type: 'subscribe',
            id: channel.subscriptionId,
            channel: toAppSyncChannel(channel.name, opts.defaultNamespace),
            authorization,
        };

        this.send(frame);
    }

    /**
     * Fetch a per-channel subscribe token: the custom `authorizer` when
     * configured, otherwise a POST to the auth endpoint mirroring
     * Laravel's broadcasting auth contract (`{socket_id, channel_name}`
     * in, `{auth: token}` out).
     *
     * @param channelName - The wire channel name to authorize.
     * @returns The subscribe token.
     */
    protected async authorizeChannel(channelName: string): Promise<string> {
        const opts = this.opts;

        if (opts.authorizer) {
            // Invoke detached: user-supplied functions must not receive
            // the options object as `this`.
            const authorizer = opts.authorizer;

            return authorizer(channelName, this.socketId());
        }

        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...(opts.auth?.headers ?? {}),
        };

        const bearer = await resolveOption(opts.bearerToken);
        if (bearer) {
            headers.Authorization = `Bearer ${bearer}`;
        }

        const request: RequestInit = {
            method: 'POST',
            headers,
            body: JSON.stringify({ socket_id: this.socketId(), channel_name: channelName }),
        };

        if (opts.credentials) {
            request.credentials = opts.credentials;
        }

        // Invoke detached: a fetch implementation — the default global
        // wrapper or a user-supplied one — must not receive the options
        // object as `this` (browser fetch throws "Illegal invocation").
        const doFetch = opts.fetch;
        const response = await doFetch(opts.authEndpoint, request);

        if (!response.ok) {
            const error = new Error(`Channel authorization failed with status ${response.status}`) as Error & { status: number };
            error.status = response.status;
            throw error;
        }

        const data = (await response.json()) as { auth: string };

        return data.auth;
    }

    /**
     * Move every live subscription back to the pending queue.
     */
    protected requeueSubscriptions(): void {
        for (const channel of this.subscriptions.values()) {
            if (!this.pendingChannels.includes(channel)) {
                this.pendingChannels.push(channel);
            }
        }

        this.subscriptions.clear();
    }

    /**
     * Send a frame when the socket is open; silently drop otherwise.
     *
     * @param frame - The frame to send.
     */
    protected send(frame: OutboundFrame): void {
        if (this.socket !== null && this.socket.readyState === WS_OPEN) {
            this.socket.send(JSON.stringify(frame));
        }
    }

    /**
     * The `host` used in handshake and subscribe authorization objects:
     * the configured `httpHost`, or the host of `wsEndpoint` with
     * `appsync-realtime-api` swapped for `appsync-api`.
     */
    protected httpHost(): string {
        const opts = this.opts;

        if (opts.httpHost) {
            return opts.httpHost;
        }

        return new URL(opts.wsEndpoint).host.replace('appsync-realtime-api', 'appsync-api');
    }

    /**
     * Arm the keep-alive timeout; any `ka` or `connection_ack` re-arms it.
     */
    protected resetKeepAliveTimer(): void {
        this.clearKeepAlive();
        this.keepAliveTimer = setTimeout(() => this.handleClose(), this.connectionTimeoutMs);
    }

    /**
     * Clear the keep-alive timeout.
     */
    protected clearKeepAlive(): void {
        if (this.keepAliveTimer !== null) {
            clearTimeout(this.keepAliveTimer);
            this.keepAliveTimer = null;
        }
    }

    /**
     * Notify all connection-status listeners.
     *
     * @param status - The new status.
     */
    protected notifyConnectionChange(status: ConnectionStatus): void {
        for (const callback of this.connectionListeners) {
            callback(status);
        }
    }

    /**
     * Log a debug message when `debug` is enabled.
     *
     * @param args - The values to log.
     */
    protected debug(...args: unknown[]): void {
        if (this.opts.debug) {
            console.warn(LOG_PREFIX, ...args);
        }
    }
}
