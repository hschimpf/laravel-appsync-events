import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AppSyncConnector } from '../src/connector.js';
import type { AppSyncConnectorOptions } from '../src/options.js';
import type { AuthErrorEvent } from '../src/protocol.js';
import { FakeWebSocket } from './helpers/FakeWebSocket.js';

const WS_ENDPOINT = 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime';
const HTTP_HOST = 'abc123.appsync-api.eu-west-1.amazonaws.com';

const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

/** Drain the microtask queue; safe to use while fake timers are installed. */
const flush = async (): Promise<void> => {
    for (let i = 0; i < 30; i++) {
        await Promise.resolve();
    }
};

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

type Setup = {
    connector: AppSyncConnector;
    fetchMock: ReturnType<typeof vi.fn>;
};

function makeConnector(extra: Partial<AppSyncConnectorOptions> = {}): Setup {
    const fetchMock = vi.fn(async () => jsonResponse({ auth: 'subscribe-token' }));

    const connector = new AppSyncConnector({
        broadcaster: AppSyncConnector,
        wsEndpoint: WS_ENDPOINT,
        connectToken: 'connect-token',
        WebSocket: FakeWebSocket,
        fetch: fetchMock,
        ...extra,
    });

    return { connector, fetchMock };
}

/** Open the last socket and answer connection_init with an ack. */
async function connectAndAck(connector: AppSyncConnector): Promise<FakeWebSocket> {
    await tick();
    const socket = FakeWebSocket.last;
    socket.serverOpen();
    socket.serverSend({ type: 'connection_ack', connectionTimeoutMs: 60_000 });
    await tick();

    return socket;
}

beforeEach(() => {
    FakeWebSocket.reset();
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('handshake', () => {
    it('opens with the aws-appsync-event-ws + header subprotocols', async () => {
        makeConnector();
        await tick();

        const socket = FakeWebSocket.last;
        expect(socket.url).toBe(WS_ENDPOINT);
        expect(socket.protocols).toHaveLength(2);
        expect(socket.protocols[0]).toBe('aws-appsync-event-ws');
        expect(socket.protocols[1]).toMatch(/^header-/);

        const header = socket.protocols[1].slice('header-'.length);
        expect(header).not.toMatch(/[+/=]/); // base64url, no padding

        expect(socket.authHeader).toEqual({ host: HTTP_HOST, Authorization: 'connect-token' });
    });

    it('derives httpHost from the wsEndpoint and honours an explicit one', async () => {
        makeConnector();
        await tick();
        expect(FakeWebSocket.last.authHeader.host).toBe(HTTP_HOST);

        FakeWebSocket.reset();
        makeConnector({ httpHost: '127.0.0.1:9400' });
        await tick();
        expect(FakeWebSocket.last.authHeader.host).toBe('127.0.0.1:9400');
    });

    it('uses x-api-key when only publicApiKey is configured', async () => {
        makeConnector({ connectToken: undefined, publicApiKey: 'da2-key' });
        await tick();

        expect(FakeWebSocket.last.authHeader).toEqual({ host: HTTP_HOST, 'x-api-key': 'da2-key' });
    });

    it('sends only host when neither token nor api key is configured', async () => {
        makeConnector({ connectToken: undefined });
        await tick();

        expect(FakeWebSocket.last.authHeader).toEqual({ host: HTTP_HOST });
    });

    it('re-evaluates a connectToken function on every connect', async () => {
        const tokens = vi.fn()
            .mockReturnValueOnce('first-token')
            .mockReturnValueOnce('second-token');

        const { connector } = makeConnector({ connectToken: tokens });
        await tick();

        expect(tokens).toHaveBeenCalledTimes(1);
        expect(FakeWebSocket.last.authHeader.Authorization).toBe('first-token');

        connector.disconnect();
        connector.connect();
        await tick();

        expect(tokens).toHaveBeenCalledTimes(2);
        expect(FakeWebSocket.last.authHeader.Authorization).toBe('second-token');
    });

    it('supports an async connectToken function', async () => {
        const tokens = vi.fn(async () => 'async-token');
        makeConnector({ connectToken: tokens });

        expect(FakeWebSocket.instances).toHaveLength(0); // no socket before the token resolves
        await tick();

        expect(FakeWebSocket.last.authHeader.Authorization).toBe('async-token');
    });

    it('does not create a socket when disconnected while the token is pending', async () => {
        let resolveToken!: (token: string) => void;
        const tokens = vi.fn(() => new Promise<string>((resolve) => { resolveToken = resolve; }));

        const { connector } = makeConnector({ connectToken: tokens });
        connector.disconnect();
        resolveToken('late-token');
        await tick();

        expect(FakeWebSocket.instances).toHaveLength(0);
    });

    it('is idempotent while a socket is connecting or open', async () => {
        const { connector } = makeConnector();
        await tick();

        connector.connect();
        connector.connect();
        await tick();

        expect(FakeWebSocket.instances).toHaveLength(1);
    });
});

describe('connection lifecycle', () => {
    it('sends connection_init on open and reports statuses on ack', async () => {
        const { connector } = makeConnector();
        const statuses: string[] = [];
        connector.onConnectionChange((status) => statuses.push(status));
        await tick();

        const socket = FakeWebSocket.last;
        expect(connector.connectionStatus()).toBe('connecting');

        socket.serverOpen();
        expect(socket.sentFrames).toContainEqual({ type: 'connection_init' });

        socket.serverSend({ type: 'connection_ack', connectionTimeoutMs: 123_000 });
        await tick();

        expect(connector.connectionStatus()).toBe('connected');
        expect(statuses).toContain('connecting');
        expect(statuses).toContain('connected');
    });

    it('returns an unsubscribe function from onConnectionChange', async () => {
        const { connector } = makeConnector();
        const statuses: string[] = [];
        const off = connector.onConnectionChange((status) => statuses.push(status));

        off();
        await connectAndAck(connector);

        expect(statuses).toHaveLength(0);
    });

    it('has a stable socketId', async () => {
        const { connector } = makeConnector();
        const id = connector.socketId();
        await connectAndAck(connector);

        expect(connector.socketId()).toBe(id);
        expect(id).toMatch(/^[0-9a-f-]{36}$/);
    });
});

describe('subscriptions', () => {
    it('subscribes a private channel after authorization', async () => {
        const { connector, fetchMock } = makeConnector();
        const socket = await connectAndAck(connector);

        const subscribed = vi.fn();
        const channel = connector.privateChannel('orders.1').subscribed(subscribed);
        await tick();

        expect(fetchMock).toHaveBeenCalledWith(
            '/broadcasting/auth',
            expect.objectContaining({ method: 'POST' }),
        );

        const [, request] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(request.headers).toMatchObject({
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        });
        expect(JSON.parse(request.body as string)).toEqual({
            socket_id: connector.socketId(),
            channel_name: 'private-orders.1',
        });

        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');
        expect(subscribe).toMatchObject({
            id: channel.subscriptionId,
            channel: '/orders/private/1',
            authorization: { host: HTTP_HOST, Authorization: 'subscribe-token' },
        });

        expect(subscribed).not.toHaveBeenCalled();
        socket.serverSend({ type: 'subscribe_success', id: channel.subscriptionId });
        expect(subscribed).toHaveBeenCalledTimes(1);
    });

    it('calls the default global fetch without an illegal receiver', async () => {
        // Browsers require fetch's receiver to be Window/undefined — a
        // detached call like `options.fetch(...)` throws "Illegal
        // invocation" before any request is sent. Node's fetch has no
        // brand check, so simulate the browser behaviour explicitly.
        const brandCheckedFetch = vi.fn(function (this: unknown, _input: RequestInfo | URL, _init?: RequestInit): Promise<Response> {
            if (this !== undefined && this !== globalThis) {
                throw new TypeError("Failed to execute 'fetch' on 'Window': Illegal invocation");
            }

            return Promise.resolve(jsonResponse({ auth: 'subscribe-token' }));
        });
        vi.stubGlobal('fetch', brandCheckedFetch);

        const connector = new AppSyncConnector({
            broadcaster: AppSyncConnector,
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
        });
        const socket = await connectAndAck(connector);

        const channel = connector.privateChannel('orders.1');
        await tick();

        expect(brandCheckedFetch).toHaveBeenCalledWith(
            '/broadcasting/auth',
            expect.objectContaining({ method: 'POST' }),
        );

        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');
        expect(subscribe).toMatchObject({
            id: channel.subscriptionId,
            channel: '/orders/private/1',
            authorization: { host: HTTP_HOST, Authorization: 'subscribe-token' },
        });
    });

    it('invokes a custom fetch without the options object as receiver', async () => {
        let receiver: unknown = Symbol('unset');
        const fetchMock = vi.fn(function (this: unknown): Promise<Response> {
            receiver = this;

            return Promise.resolve(jsonResponse({ auth: 'subscribe-token' }));
        });

        const connector = new AppSyncConnector({
            broadcaster: AppSyncConnector,
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        });
        const socket = await connectAndAck(connector);

        connector.privateChannel('orders.1');
        await tick();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(receiver).toBeUndefined();
        expect(socket.sentFrames.find((frame) => frame.type === 'subscribe')).toBeDefined();
    });

    it('honours a custom authEndpoint, headers, credentials and string bearer', async () => {
        const { connector, fetchMock } = makeConnector({
            authEndpoint: '/custom/auth',
            auth: { headers: { 'X-CSRF-TOKEN': 'csrf' } },
            bearerToken: 'bearer-1',
            credentials: 'include',
        });
        await connectAndAck(connector);

        connector.privateChannel('orders.1');
        await tick();

        const [url, request] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/custom/auth');
        expect(request.credentials).toBe('include');
        expect(request.headers).toMatchObject({
            'X-CSRF-TOKEN': 'csrf',
            'Authorization': 'Bearer bearer-1',
        });
    });

    it('supports a function bearerToken', async () => {
        const { connector, fetchMock } = makeConnector({
            bearerToken: async () => 'bearer-fn',
        });
        await connectAndAck(connector);

        connector.privateChannel('orders.1');
        await tick();

        const [, request] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(request.headers).toMatchObject({ Authorization: 'Bearer bearer-fn' });
    });

    it('uses a custom authorizer instead of fetch', async () => {
        const authorizer = vi.fn(async () => 'custom-sub-token');
        const { connector, fetchMock } = makeConnector({ authorizer });
        await connectAndAck(connector);

        connector.privateChannel('orders.1');
        await tick();

        expect(authorizer).toHaveBeenCalledWith('private-orders.1', connector.socketId());
        expect(fetchMock).not.toHaveBeenCalled();
        expect(FakeWebSocket.last.sentFrames.find((frame) => frame.type === 'subscribe'))
            .toMatchObject({ authorization: { host: HTTP_HOST, Authorization: 'custom-sub-token' } });
    });

    it('subscribes a public channel with just the host', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        connector.channel('news');
        await tick();

        expect(socket.sentFrames.find((frame) => frame.type === 'subscribe')).toMatchObject({
            channel: '/default/public/news',
            authorization: { host: HTTP_HOST },
        });
    });

    it('adds x-api-key to public subscriptions when publicApiKey is set', async () => {
        const { connector } = makeConnector({ publicApiKey: 'da2-key' });
        const socket = await connectAndAck(connector);

        connector.channel('news');
        await tick();

        expect(socket.sentFrames.find((frame) => frame.type === 'subscribe')).toMatchObject({
            channel: '/default/public/news',
            authorization: { host: HTTP_HOST, 'x-api-key': 'da2-key' },
        });
    });

    it('maps channel names using a custom defaultNamespace', async () => {
        const { connector } = makeConnector({ defaultNamespace: 'tenant' });
        const socket = await connectAndAck(connector);

        connector.privateChannel('solo');
        await tick();

        expect(socket.sentFrames.find((frame) => frame.type === 'subscribe')).toMatchObject({
            channel: '/tenant/private/solo',
        });
    });

    it('invokes error callbacks on subscribe_error', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const onError = vi.fn();
        const channel = connector.privateChannel('orders.1').error(onError);
        await tick();

        socket.serverSend({ type: 'subscribe_error', id: channel.subscriptionId, reason: 'nope' });
        expect(onError).toHaveBeenCalledWith(expect.objectContaining({ type: 'subscribe_error', reason: 'nope' }));
    });

    it('drops the subscription and reports auth_error when authorization fails', async () => {
        const authFetch = vi.fn(async () => jsonResponse({ message: 'nope' }, 403));
        const { connector } = makeConnector({
            fetch: authFetch as unknown as typeof fetch,
        });
        const socket = await connectAndAck(connector);

        const onError = vi.fn();
        connector.privateChannel('orders.1').error(onError);
        await tick();

        expect(authFetch).toHaveBeenCalled();
        expect(socket.sentFrames.find((frame) => frame.type === 'subscribe')).toBeUndefined();
        expect(onError).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'auth_error', status: 403 } satisfies Partial<AuthErrorEvent>),
        );
    });

    it('unsubscribes with a frame and removes the channel', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const channel = connector.privateChannel('orders.1');
        await tick();
        socket.serverSend({ type: 'subscribe_success', id: channel.subscriptionId });

        const sentBefore = socket.sent.length;
        connector.leaveChannel('private-orders.1');

        expect(socket.sentFrames.at(-1)).toEqual({ type: 'unsubscribe', id: channel.subscriptionId });
        expect(socket.sent.length).toBe(sentBefore + 1);
        expect(connector.channel('private-orders.1')).not.toBe(channel); // recreated
    });

    it('leaves public, private and presence variants without throwing', async () => {
        const { connector } = makeConnector();
        await connectAndAck(connector);

        connector.privateChannel('orders.1');
        connector.channel('orders.1');
        await tick();

        expect(() => connector.leave('orders.1')).not.toThrow();
        expect(connector.channel('orders.1').name).toBe('orders.1'); // recreated
    });

    it('throws for presence channels', () => {
        const { connector } = makeConnector();

        expect(() => connector.presenceChannel('room.1'))
            .toThrow('Presence channels are not supported by the AppSync connector yet');
    });
});

describe('event dispatch', () => {
    it('dispatches data frames (single and array) to listeners', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const received: unknown[] = [];
        const channel = connector.channel('orders.1').listen('.Shipped', (payload: unknown) => received.push(payload));
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: { id: 1 } }),
        });
        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: [
                JSON.stringify({ event: 'Shipped', data: { id: 2 } }),
                JSON.stringify({ event: 'Shipped', data: { id: 3 } }),
            ],
        });

        expect(received).toEqual([{ id: 1 }, { id: 2 }, { id: 3 }]);
    });

    it('drops events whose envelope socket matches our own socketId (toOthers)', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const received = vi.fn();
        const channel = connector.channel('orders.1').listen('.Shipped', received);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: { id: 1 }, socket: connector.socketId() }),
        });

        expect(received).not.toHaveBeenCalled();
    });

    it('delivers events whose envelope socket is a different socket id', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const received = vi.fn();
        const channel = connector.channel('orders.1').listen('.Shipped', received);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: { id: 1 }, socket: 'someone-else' }),
        });

        expect(received).toHaveBeenCalledWith({ id: 1 });
    });

    it('delivers events without a socket envelope field', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const received = vi.fn();
        const channel = connector.channel('orders.1').listen('.Shipped', received);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: { id: 1 } }),
        });

        expect(received).toHaveBeenCalledWith({ id: 1 });
    });

    it('applies the Echo event namespace and supports multiple listeners', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const a = vi.fn();
        const b = vi.fn();
        const channel = connector.channel('orders.1').listen('Shipped', a).listen('Shipped', b);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'App\\Events\\Shipped', data: { id: 9 } }),
        });

        expect(a).toHaveBeenCalledWith({ id: 9 });
        expect(b).toHaveBeenCalledWith({ id: 9 });
    });

    it('stopListening removes one callback or all of them', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const a = vi.fn();
        const b = vi.fn();
        const channel = connector.channel('orders.1').listen('.Shipped', a).listen('.Shipped', b);
        await tick();

        const fire = (): void => socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: {} }),
        });

        channel.stopListening('.Shipped', a);
        fire();
        expect(a).toHaveBeenCalledTimes(0);
        expect(b).toHaveBeenCalledTimes(1);

        channel.stopListening('.Shipped');
        fire();
        expect(b).toHaveBeenCalledTimes(1);
    });

    it('isolates a throwing listener from the others', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const good = vi.fn();
        const channel = connector.channel('orders.1')
            .listen('.Shipped', () => { throw new Error('boom'); })
            .listen('.Shipped', good);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({ event: 'Shipped', data: 1 }),
        });

        expect(good).toHaveBeenCalledWith(1);
    });

    it('receives Laravel notifications through notification()', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const onNotification = vi.fn();
        const channel = connector.channel('orders.1').notification(onNotification);
        await tick();

        socket.serverSend({
            type: 'data',
            id: channel.subscriptionId,
            event: JSON.stringify({
                event: 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated',
                data: { id: 'n-1', type: 'InvoicePaid' },
            }),
        });

        expect(onNotification).toHaveBeenCalledWith({ id: 'n-1', type: 'InvoicePaid' });
    });

    it('ignores malformed frames without throwing', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        const channel = connector.channel('orders.1');
        await tick();

        expect(() => {
            socket.serverSend('not json at all{{{');
            socket.serverSend({ type: 'data', id: channel.subscriptionId, event: ['{{bad', 'also bad'] });
            socket.serverSend({ type: 'totally-unknown', id: 'x' });
        }).not.toThrow();
    });
});

describe('reconnects and keep-alive', () => {
    it('reconnects after the socket closes and re-subscribes with a fresh token', async () => {
        const { connector, fetchMock } = makeConnector();
        const socket = await connectAndAck(connector);
        connector.privateChannel('orders.1');
        await tick();
        const firstAuthCalls = fetchMock.mock.calls.length;
        const statuses: string[] = [];
        connector.onConnectionChange((status) => statuses.push(status));

        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0.5); // 0.75x jitter -> first delay ~750ms
        socket.serverClose();

        expect(connector.connectionStatus()).toBe('reconnecting');
        expect(statuses.at(-2)).toBe('disconnected');
        expect(statuses.at(-1)).toBe('reconnecting');

        await vi.advanceTimersByTimeAsync(800);
        await flush();

        const socket2 = FakeWebSocket.last;
        expect(socket2).not.toBe(socket);
        socket2.serverOpen();
        socket2.serverSend({ type: 'connection_ack' });
        await vi.advanceTimersByTimeAsync(0);
        await flush();

        // re-subscribed with a fresh authorization request
        expect(fetchMock.mock.calls.length).toBeGreaterThan(firstAuthCalls);
        expect(socket2.sentFrames.find((frame) => frame.type === 'subscribe')).toBeDefined();
    });

    it('applies exponential backoff capped at capMs', async () => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0.9999); // ~1x jitter

        const { connector } = makeConnector({ reconnect: { baseMs: 100, capMs: 450 } });
        await vi.advanceTimersByTimeAsync(0);
        const socket = FakeWebSocket.last;
        socket.serverOpen();
        socket.serverSend({ type: 'connection_ack' });
        await vi.advanceTimersByTimeAsync(0);

        const instancesAtClose: number[] = [];
        const measure = async (): Promise<void> => {
            FakeWebSocket.last.serverClose();
            const before = FakeWebSocket.instances.length;
            for (let t = 0; t < 5000 && FakeWebSocket.instances.length === before; t += 10) {
                await vi.advanceTimersByTimeAsync(10);
            }
            instancesAtClose.push(FakeWebSocket.instances.length);
            FakeWebSocket.last.serverOpen();
            FakeWebSocket.last.serverSend({ type: 'connection_ack' });
            await vi.advanceTimersByTimeAsync(0);
        };

        // close #1 -> ~100ms, close #2 -> ~200ms, close #3 -> ~400ms, close #4 -> ~450ms (capped)
        await measure();
        await measure();
        await measure();
        await measure();

        expect(instancesAtClose).toEqual([2, 3, 4, 5]);
    });

    it('reconnects on connection_error frames', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);

        vi.useFakeTimers();
        socket.serverSend({ type: 'connection_error', reason: 'boom' });
        expect(connector.connectionStatus()).toBe('reconnecting');

        await vi.advanceTimersByTimeAsync(30_000);
        await flush();
        expect(FakeWebSocket.instances.length).toBe(2);
    });

    it('reconnects when the keep-alive timeout fires, and ka re-arms it', async () => {
        vi.useFakeTimers();

        const { connector } = makeConnector();
        await vi.advanceTimersByTimeAsync(0);
        const socket = FakeWebSocket.last;
        socket.serverOpen();
        socket.serverSend({ type: 'connection_ack', connectionTimeoutMs: 1_000 });
        await vi.advanceTimersByTimeAsync(0);

        await vi.advanceTimersByTimeAsync(900);
        socket.serverSend({ type: 'ka' }); // re-arms the timer
        await vi.advanceTimersByTimeAsync(900);
        expect(connector.connectionStatus()).toBe('connected');

        await vi.advanceTimersByTimeAsync(150); // 1050ms since the ka
        expect(connector.connectionStatus()).toBe('reconnecting');

        await vi.advanceTimersByTimeAsync(30_000);
        await flush();
        expect(FakeWebSocket.instances.length).toBe(2);
    });

    it('ignores a stale authorization result arriving after a disconnect', async () => {
        let resolveAuth!: (value: Response) => void;
        const authFetch = vi.fn(() => new Promise<Response>((resolve) => { resolveAuth = resolve; }));
        const { connector } = makeConnector({
            fetch: authFetch as unknown as typeof fetch,
        });
        const socket = await connectAndAck(connector);

        connector.privateChannel('orders.1');
        await tick();
        expect(authFetch).toHaveBeenCalled();

        connector.disconnect();
        resolveAuth(jsonResponse({ auth: 'stale-token' }));
        await tick();

        expect(socket.sentFrames.filter((frame) => frame.type === 'subscribe')).toHaveLength(0);
    });

    it('disconnect cancels pending reconnects and connect() re-subscribes', async () => {
        const { connector } = makeConnector();
        const socket = await connectAndAck(connector);
        connector.privateChannel('orders.1');
        await tick();

        vi.useFakeTimers();
        socket.serverClose();
        connector.disconnect();

        await vi.advanceTimersByTimeAsync(60_000);
        expect(FakeWebSocket.instances).toHaveLength(1); // no reconnect happened
        expect(connector.connectionStatus()).toBe('disconnected');

        connector.connect();
        await vi.advanceTimersByTimeAsync(0);
        await flush();

        const socket2 = FakeWebSocket.last;
        expect(socket2).not.toBe(socket);
        socket2.serverOpen();
        socket2.serverSend({ type: 'connection_ack' });
        await vi.advanceTimersByTimeAsync(0);
        await flush();

        expect(socket2.sentFrames.find((frame) => frame.type === 'subscribe')).toBeDefined();
    });
});
