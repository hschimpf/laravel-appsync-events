import Echo from 'laravel-echo';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AppSyncConnector } from '../src/connector.js';
import { appSyncEchoOptions } from '../src/options.js';
import { FakeWebSocket } from './helpers/FakeWebSocket.js';

const WS_ENDPOINT = 'wss://abc123.appsync-realtime-api.eu-west-1.amazonaws.com/event/realtime';

const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function openAndAck(): Promise<FakeWebSocket> {
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
});

describe('laravel-echo integration', () => {
    it('works through new Echo(appSyncEchoOptions(...))', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ auth: 'subscribe-token' }));
        const echo = new Echo(appSyncEchoOptions({
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        }));

        const socket = await openAndAck();
        expect(echo.socketId()).toMatch(/^[0-9a-f-]{36}$/);
        expect(echo.connectionStatus()).toBe('connected');

        const received: unknown[] = [];
        const channel = echo.private('orders.1').listen('.Shipped', (payload: unknown) => received.push(payload));
        await tick();

        expect(fetchMock).toHaveBeenCalledWith('/broadcasting/auth', expect.objectContaining({ method: 'POST' }));
        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');
        expect(subscribe).toMatchObject({ channel: '/private/orders/1' });

        socket.serverSend({
            type: 'data',
            id: subscribe!.id,
            event: JSON.stringify({ event: 'Shipped', data: { order: 7 } }),
        });
        expect(received).toEqual([{ order: 7 }]);

        expect(channel.subscriptionId).toBe(subscribe!.id);
    });

    it('applies the configured event namespace to unprefixed event names', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ auth: 't' }));
        const echo = new Echo(appSyncEchoOptions({
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        }));

        const socket = await openAndAck();
        const onShipped = vi.fn();
        echo.private('orders.1').listen('Shipped', onShipped);
        await tick();
        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');

        socket.serverSend({
            type: 'data',
            id: subscribe!.id,
            event: JSON.stringify({ event: 'App\\Events\\Shipped', data: { order: 1 } }),
        });

        expect(onShipped).toHaveBeenCalledWith({ order: 1 });
    });

    it('delivers Laravel notification payloads through notification()', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ auth: 't' }));
        const echo = new Echo(appSyncEchoOptions({
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        }));

        const socket = await openAndAck();
        const onNotification = vi.fn();
        echo.private('orders.1').notification(onNotification);
        await tick();
        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');

        socket.serverSend({
            type: 'data',
            id: subscribe!.id,
            event: JSON.stringify({
                event: 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated',
                data: { id: 'n-1', type: 'InvoicePaid' },
            }),
        });

        expect(onNotification).toHaveBeenCalledWith({ id: 'n-1', type: 'InvoicePaid' });
    });

    it('leave() unsubscribes and disconnect() closes the socket', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ auth: 't' }));
        const echo = new Echo(appSyncEchoOptions({
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        }));

        const socket = await openAndAck();
        echo.private('orders.1').listen('.Shipped', vi.fn());
        await tick();
        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');

        echo.leave('orders.1');
        expect(socket.sentFrames.at(-1)).toEqual({ type: 'unsubscribe', id: subscribe!.id });

        echo.disconnect();
        expect(socket.readyState).toBe(3); // CLOSED
        expect(echo.connectionStatus()).toBe('disconnected');
    });

    it('also works with the plain broadcaster form', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ auth: 't' }));
        const echo = new Echo({
            broadcaster: AppSyncConnector,
            wsEndpoint: WS_ENDPOINT,
            connectToken: 'connect-token',
            WebSocket: FakeWebSocket,
            fetch: fetchMock,
        });

        const socket = await openAndAck();
        const onEvent = vi.fn();
        echo.channel('news').listen('.Announcement', onEvent);
        await tick();

        const subscribe = socket.sentFrames.find((frame) => frame.type === 'subscribe');
        expect(subscribe).toMatchObject({ channel: '/public/news' });

        socket.serverSend({
            type: 'data',
            id: subscribe!.id,
            event: JSON.stringify({ event: 'Announcement', data: 'hi' }),
        });
        expect(onEvent).toHaveBeenCalledWith('hi');
    });
});
