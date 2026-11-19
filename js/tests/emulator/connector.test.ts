import { describe, expect, vi } from 'vitest';

import { AppSyncConnector } from '../../src/connector.js';
import type { AppSyncConnectorOptions } from '../../src/options.js';
import { emulatorIt, publishEvent, waitFor } from './harness.js';

/**
 * End-to-end connector tests against the real emulator realtime socket,
 * using Node's native WebSocket/fetch. NOTE: the emulator validates the
 * API key but not token contents or SigV4 signatures — token/authorizer
 * correctness is covered by the PHP unit tests.
 */

const options = (
    wsEndpoint: string,
    httpHost: string,
    extra: Partial<AppSyncConnectorOptions> = {},
): AppSyncConnectorOptions & { broadcaster: typeof AppSyncConnector } => ({
    broadcaster: AppSyncConnector,
    wsEndpoint,
    httpHost,
    ...extra,
});

describe('connector against the emulator', () => {
    emulatorIt('receives public-channel events over an API-key connection', async (api) => {
        const received: unknown[] = [];
        const subscribed = vi.fn();
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: api.apiKey,
        }));
        const off = connector.onConnectionChange(() => undefined);

        connector.channel('orders.js-pub').subscribed(subscribed).listen('.JsShipped', (p: unknown) => received.push(p));

        await waitFor(() => subscribed.mock.calls.length > 0, 10_000, 'subscribe_success');

        await publishEvent(api, '/public/orders/js-pub', 'JsShipped', { order: 1 });
        await waitFor(() => received.length > 0, 10_000, 'data frame');

        expect(received).toEqual([{ order: 1 }]);

        off();
        connector.disconnect();
    });

    emulatorIt('receives private-channel events with function tokens and a custom authorizer', async (api) => {
        // The emulator accepts any presented token — token validity is
        // asserted by the PHP unit tests, not here.
        const connectToken = vi.fn(() => 'emulator-connect-token');
        const authorizer = vi.fn(async () => 'emulator-subscribe-token');
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            connectToken,
            authorizer,
        }));

        const received: unknown[] = [];
        const subscribed = vi.fn();
        connector.privateChannel('orders.js-priv')
            .subscribed(subscribed)
            .listen('.JsPriv', (p: unknown) => received.push(p));

        await waitFor(() => subscribed.mock.calls.length > 0, 10_000, 'subscribe_success');
        expect(connectToken).toHaveBeenCalled();
        expect(authorizer).toHaveBeenCalledWith('private-orders.js-priv', connector.socketId());

        await publishEvent(api, '/private/orders/js-priv', 'JsPriv', { order: 2 });
        await waitFor(() => received.length > 0, 10_000, 'data frame');

        expect(received).toEqual([{ order: 2 }]);

        connector.disconnect();
    });

    emulatorIt('dispatches to multiple listeners and stopListening removes them', async (api) => {
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: api.apiKey,
        }));

        const a = vi.fn();
        const b = vi.fn();
        const channel = connector.channel('orders.js-multi');
        const subscribed = vi.fn();
        channel.subscribed(subscribed).listen('.Multi', a).listen('.Multi', b);

        await waitFor(() => subscribed.mock.calls.length > 0, 10_000, 'subscribe_success');
        await publishEvent(api, '/public/orders/js-multi', 'Multi', 1);
        await waitFor(() => a.mock.calls.length > 0 && b.mock.calls.length > 0, 10_000, 'both listeners');

        channel.stopListening('.Multi', a);
        await publishEvent(api, '/public/orders/js-multi', 'Multi', 2);
        await waitFor(() => b.mock.calls.length === 2, 10_000, 'second event on b');
        expect(a).toHaveBeenCalledTimes(1);

        channel.stopListening('.Multi');
        await publishEvent(api, '/public/orders/js-multi', 'Multi', 3);
        await new Promise((resolve) => setTimeout(resolve, 500));
        expect(b).toHaveBeenCalledTimes(2);

        connector.disconnect();
    });

    emulatorIt('stops delivery after leave()', async (api) => {
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: api.apiKey,
        }));

        const received: unknown[] = [];
        const subscribed = vi.fn();
        connector.channel('orders.js-leave').subscribed(subscribed).listen('.Leave', (p: unknown) => received.push(p));

        await waitFor(() => subscribed.mock.calls.length > 0, 10_000, 'subscribe_success');
        await publishEvent(api, '/public/orders/js-leave', 'Leave', 1);
        await waitFor(() => received.length > 0, 10_000, 'first event');

        connector.leave('orders.js-leave');

        await publishEvent(api, '/public/orders/js-leave', 'Leave', 2);
        await new Promise((resolve) => setTimeout(resolve, 500));

        expect(received).toEqual([1]);

        connector.disconnect();
    });

    emulatorIt('surfaces subscribe_error for an unknown namespace without crashing', async (api) => {
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: api.apiKey,
            publicNamespace: 'ghost',
        }));

        const errors: unknown[] = [];
        connector.channel('ns-1').error((error: unknown) => errors.push(error));

        const error = await waitFor(
            () => errors.at(-1),
            10_000,
            'subscribe_error for an unknown namespace',
        );

        expect(error).toMatchObject({ type: 'subscribe_error' });

        connector.disconnect();
    });

    emulatorIt('resubscribes and keeps receiving after disconnect() + connect()', async (api) => {
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: api.apiKey,
        }));

        const received: unknown[] = [];
        const subscribed = vi.fn();
        connector.channel('orders.js-re').subscribed(subscribed).listen('.Re', (p: unknown) => received.push(p));

        await waitFor(() => subscribed.mock.calls.length === 1, 10_000, 'initial subscribe_success');

        connector.disconnect();
        connector.connect();

        await waitFor(() => subscribed.mock.calls.length === 2, 10_000, 're-subscribe_success');

        await publishEvent(api, '/public/orders/js-re', 'Re', { again: true });
        await waitFor(() => received.length > 0, 10_000, 'event after reconnect');

        expect(received).toEqual([{ again: true }]);

        connector.disconnect();
    });

    emulatorIt('transitions through reconnecting/disconnected on a rejected API key', async (api) => {
        const statuses: string[] = [];
        const connector = new AppSyncConnector(options(api.realtimeEndpoint, api.httpHost, {
            publicApiKey: 'da2-invalid-key',
            reconnect: { baseMs: 100, capMs: 400 },
        }));
        connector.onConnectionChange((status) => statuses.push(status));

        connector.channel('orders.js-bad').listen('.Bad', () => undefined);

        // The upgrade is refused (401): the connector must cycle through
        // reconnecting without throwing, then stop when we disconnect().
        await waitFor(() => statuses.includes('reconnecting'), 10_000, 'reconnecting status');
        expect(connector.connectionStatus()).toBe('reconnecting');

        connector.disconnect();
        expect(connector.connectionStatus()).toBe('disconnected');
        expect(statuses.at(-1)).toBe('disconnected');
    });
});
