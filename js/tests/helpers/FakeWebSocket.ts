import type { AppSyncWebSocketLike } from '../../src/options.js';

/**
 * A controllable WebSocket test double: records every instance, the URL
 * and subprotocols it was constructed with, and every sent frame. Tests
 * drive the server side via {@link serverOpen}, {@link serverSend} and
 * {@link serverClose}.
 */
export class FakeWebSocket implements AppSyncWebSocketLike {
    /**
     * Every instance created through this constructor, in order.
     */
    public static instances: FakeWebSocket[] = [];

    /**
     * Forget all previously created instances.
     */
    public static reset(): void {
        FakeWebSocket.instances = [];
    }

    /**
     * The most recently created instance.
     */
    public static get last(): FakeWebSocket {
        const instance = FakeWebSocket.instances[FakeWebSocket.instances.length - 1];
        if (!instance) {
            throw new Error('No FakeWebSocket has been created');
        }

        return instance;
    }

    public readyState = 0;
    public onopen: ((event: unknown) => void) | null = null;
    public onmessage: ((event: { data: unknown }) => void) | null = null;
    public onclose: ((event: unknown) => void) | null = null;
    public onerror: ((event: unknown) => void) | null = null;

    /**
     * Raw payloads passed to {@link send}.
     */
    public readonly sent: string[] = [];

    /**
     * Whether {@link close} was called.
     */
    public closed = false;

    /**
     * The URL the socket was constructed with.
     */
    public readonly url: string;

    /**
     * The subprotocols the socket was constructed with.
     */
    public readonly protocols: string[];

    public constructor(url: string, protocols?: string | string[]) {
        this.url = url;
        this.protocols = Array.isArray(protocols) ? protocols : protocols ? [protocols] : [];
        FakeWebSocket.instances.push(this);
    }

    public send(data: string): void {
        this.sent.push(data);
    }

    public close(): void {
        this.closed = true;
        this.readyState = 3;
    }

    /**
     * Every sent payload, JSON-parsed.
     */
    public get sentFrames(): Array<Record<string, unknown>> {
        return this.sent.map((frame) => JSON.parse(frame) as Record<string, unknown>);
    }

    /**
     * The `header-` subprotocol payload, base64url-decoded and parsed.
     */
    public get authHeader(): Record<string, string> {
        const header = this.protocols
            .find((protocol) => protocol.startsWith('header-'))
            ?.slice('header-'.length);

        if (!header) {
            throw new Error('No header-* subprotocol was sent');
        }

        const base64 = header.replace(/-/g, '+').replace(/_/g, '/');
        return JSON.parse(Buffer.from(base64, 'base64').toString('utf8')) as Record<string, string>;
    }

    /**
     * Simulate the socket opening.
     */
    public serverOpen(): void {
        this.readyState = 1;
        this.onopen?.({});
    }

    /**
     * Simulate a server frame. Objects are JSON-encoded for you.
     */
    public serverSend(frame: unknown): void {
        this.onmessage?.({ data: typeof frame === 'string' ? frame : JSON.stringify(frame) });
    }

    /**
     * Simulate the server closing the connection.
     */
    public serverClose(): void {
        this.readyState = 3;
        this.onclose?.({});
    }

    /**
     * Simulate a socket-level error.
     */
    public serverError(): void {
        this.onerror?.({});
    }
}
